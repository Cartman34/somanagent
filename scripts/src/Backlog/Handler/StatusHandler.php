<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Handler;

use SoManAgent\Script\Backlog\BacklogBoard;
use SoManAgent\Script\Backlog\BacklogCommandName;
use SoManAgent\Script\Backlog\BacklogEntryResolver;
use SoManAgent\Script\Backlog\BacklogEntryService;
use SoManAgent\Script\Backlog\BacklogMetaValue;
use SoManAgent\Script\Backlog\BacklogWorktreeManager;
use SoManAgent\Script\Backlog\BoardEntry;
use SoManAgent\Script\Backlog\WorktreeAction;
use SoManAgent\Script\Backlog\WorktreeState;
use SoManAgent\Script\Client\ConsoleClient;
use SoManAgent\Script\Console;

/**
 * Handler for the status command.
 */
final class StatusHandler extends AbstractBacklogHandler
{
    private BacklogEntryResolver $entryResolver;

    private BacklogEntryService $entryService;

    private BacklogWorktreeManager $worktreeManager;

    private ConsoleClient $consoleClient;

    public function __construct(
        Console $console,
        bool $dryRun,
        string $projectRoot,
        BacklogEntryResolver $entryResolver,
        BacklogEntryService $entryService,
        BacklogWorktreeManager $worktreeManager,
        ConsoleClient $consoleClient
    ) {
        parent::__construct($console, $dryRun, $projectRoot);
        $this->entryResolver = $entryResolver;
        $this->entryService = $entryService;
        $this->worktreeManager = $worktreeManager;
        $this->consoleClient = $consoleClient;
    }

    public function handle(array $commandArgs, array $options): void
    {
        $board = $this->loadBoard();
        $agent = $options['agent'] ?? null;
        if (!is_string($agent) && $agent !== null) {
            throw new \RuntimeException('Option --agent must be a string.');
        }

        if ($agent !== null) {
            $this->statusForAgent($board, $agent);

            return;
        }

        $requestedTarget = $commandArgs[0] ?? null;
        if ($requestedTarget !== null) {
            $this->statusForFeature($board, $requestedTarget);

            return;
        }

        $this->statusGeneral($board);
    }

    private function statusGeneral(BacklogBoard $board): void
    {
        foreach (BacklogBoard::activeStages() as $stage) {
            $this->console->line('[' . BacklogBoard::stageLabel($stage) . ']');
            $matches = $board->findFeaturesByStage($stage);
            if ($matches === []) {
                $this->console->line('  none');
            } else {
                foreach ($matches as $match) {
                    $entry = $match->getEntry();
                    $parts = [
                        $entry->getFeature() ?? '-',
                        'agent=' . ($entry->getAgent() ?? '-'),
                        'pr=' . $this->describePrStatus($entry),
                    ];
                    if ($entry->isBlocked()) {
                        $parts[] = 'blocked=yes';
                    }
                    $this->console->line('- ' . implode(' ', $parts));
                }
            }
            $this->console->line('');
        }

        $this->console->line('[' . BacklogBoard::SECTION_TODO . ']');
        $reserved = $board->findReservedTasks();
        if ($reserved === []) {
            $this->console->line('  none');
        } else {
            foreach ($reserved as $match) {
                $entry = $match->getEntry();
                $parts = [
                    'agent=' . ($entry->getAgent() ?? '-'),
                ];
                if ($this->entryService->isTaskEntry($entry)) {
                    $parts[] = 'task=' . ($entry->getTask() ?? '-');
                    $parts[] = 'feature-branch=' . ($entry->getFeatureBranch() ?? '-');
                }
                if ($entry->isBlocked()) {
                    $parts[] = 'blocked=yes';
                }
                $this->console->line('- ' . implode(' ', $parts));
            }
        }
    }

    private function statusForAgent(BacklogBoard $board, string $agent): void
    {
        $taskEntry = $this->entryResolver->findTaskEntriesByAgent($board, $agent)[0] ?? null;
        $featureEntry = $this->entryResolver->findFeatureEntriesByAgent($board, $agent)[0] ?? null;

        if ($taskEntry !== null) {
            $taskEntry = $taskEntry->getEntry();
            $this->console->line('[Task]');
            $this->printStatusEntry($taskEntry);
            $this->console->line('Next: ' . $this->nextStepForEntry($taskEntry, $this->entryService->featureStage($taskEntry)));
        } else {
            $this->console->line('[Task]');
            $this->console->line('Active: ' . BacklogMetaValue::NONE->value);
        }

        if ($featureEntry !== null) {
            $featureEntry = $featureEntry->getEntry();
            $this->console->line('[Feature]');
            $this->printStatusEntry($featureEntry);
            $this->console->line('Next: ' . $this->nextStepForEntry($featureEntry, $this->entryService->featureStage($featureEntry)));
        } else {
            $this->console->line('[Feature]');
            $this->console->line('Active: ' . BacklogMetaValue::NONE->value);
        }

        $this->printStatusWorktree($board, $agent);
    }

    private function statusForFeature(BacklogBoard $board, string $requestedTarget): void
    {
        $target = $this->entryService->normalizeFeatureSlug($requestedTarget);
        $match = $this->entryResolver->requireFeature($board, $target);

        $entry = $match->getEntry();
        $stage = $this->entryService->featureStage($entry);

        $this->console->line('[Feature]');
        $this->console->line('Feature: ' . ($entry->getFeature() ?? '-'));
        $this->console->line('Agent: ' . ($entry->getAgent() ?? '-'));
        $this->console->line('Stage: ' . BacklogBoard::stageLabel($stage));
        $this->console->line('PR: ' . $this->describePrStatus($entry));
        $this->console->line('Summary: ' . $entry->getText());
        $this->printEntryStatusDetails($entry);
        $this->console->line('Next: ' . $this->nextStepForEntry($entry, $stage));
        $this->console->line('Blocker: ' . ($entry->isBlocked() ? 'blocked' : '-'));

        $this->printStatusWorktree($board, $entry->getAgent());
    }

    private function printStatusEntry(BoardEntry $entry): void
    {
        $this->console->line('Kind: ' . $this->entryService->entryKind($entry));
        $this->console->line('Feature: ' . ($entry->getFeature() ?? '-'));
        if ($this->entryService->isTaskEntry($entry)) {
            $this->console->line('Task: ' . ($entry->getTask() ?? '-'));
        }
        $this->console->line('Stage: ' . BacklogBoard::stageLabel($this->entryService->featureStage($entry)));
        $this->console->line('PR: ' . $this->describePrStatus($entry));
        $this->console->line('Summary: ' . $entry->getText());
        $this->printEntryStatusDetails($entry);
    }

    private function printEntryStatusDetails(BoardEntry $entry): void
    {
        $extraLines = $entry->getExtraLines();
        if ($extraLines !== []) {
            $this->console->line('Details:');
            foreach ($extraLines as $line) {
                $this->console->line($line);
            }
        }
    }

    private function printStatusWorktree(BacklogBoard $board, ?string $agent): void
    {
        $this->console->line('[Worktree]');
        if ($agent === null) {
            $this->console->line('State: unknown');
            $this->console->line('Path: -');

            return;
        }

        $expectedPath = $this->projectRoot . '/.worktrees/' . $agent;
        $expectedRelativePath = $this->consoleClient->toRelativeProjectPath($expectedPath);
        $worktree = null;
        foreach ($this->worktreeManager->classifyWorktrees($board)->getManaged() as $item) {
            if ($item->getPath() === $expectedPath) {
                $worktree = $item;
                break;
            }
        }

        if ($worktree === null) {
            $this->console->line('State: absent');
            $this->console->line('Path: ' . $expectedRelativePath);

            return;
        }

        $this->console->line('State: ' . $this->statusWorktreeStateLabel($worktree->getState()->value));
        $this->console->line('Path: ' . $expectedRelativePath);
        $this->console->line('Branch: ' . ($worktree->getBranch() ?? '-'));
        $this->console->line('Action: ' . $worktree->getAction()->value);
    }

    private function statusWorktreeStateLabel(string $state): string
    {
        return match ($state) {
            WorktreeState::ACTIVE->value => WorktreeAction::CLEAN->value,
            WorktreeState::DIRTY->value => WorktreeState::DIRTY->value,
            WorktreeState::BLOCKED->value => 'inconsistent',
            WorktreeState::DETACHED_MANAGED->value => 'detached',
            WorktreeState::PRUNABLE->value => WorktreeState::PRUNABLE->value,
            WorktreeState::ORPHAN->value => WorktreeState::ORPHAN->value,
            default => $state,
        };
    }

    private function describePrStatus(BoardEntry $entry): string
    {
        $storedPrNumber = $this->storedPrNumber($entry);
        if ($storedPrNumber !== null) {
            return '#' . $storedPrNumber;
        }

        $branch = $entry->getBranch();
        if ($branch === null) {
            return BacklogMetaValue::NONE->value;
        }

        return BacklogMetaValue::NONE->value;
    }

    private function storedPrNumber(BoardEntry $entry): ?int
    {
        $pr = $entry->getPr();
        if ($pr === null || $pr === BacklogMetaValue::NONE->value) {
            return null;
        }

        return (int) $pr;
    }

    private function nextStepForEntry(BoardEntry $entry, string $stage): string
    {
        return $this->entryService->isTaskEntry($entry)
            ? $this->nextStepForTaskStage($stage)
            : $this->nextStepForStage($stage);
    }

    private function nextStepForStage(string $stage): string
    {
        return match ($stage) {
            BacklogBoard::STAGE_IN_PROGRESS => BacklogCommandName::FEATURE_REVIEW_REQUEST->value,
            BacklogBoard::STAGE_IN_REVIEW => BacklogCommandName::FEATURE_REVIEW_CHECK->value . ' or ' . BacklogCommandName::FEATURE_REVIEW_APPROVE->value,
            BacklogBoard::STAGE_REJECTED => BacklogCommandName::FEATURE_REWORK->value,
            BacklogBoard::STAGE_APPROVED => BacklogCommandName::FEATURE_MERGE->value,
            default => '-',
        };
    }

    private function nextStepForTaskStage(string $stage): string
    {
        return match ($stage) {
            BacklogBoard::STAGE_IN_PROGRESS => BacklogCommandName::TASK_REVIEW_REQUEST->value,
            BacklogBoard::STAGE_IN_REVIEW => BacklogCommandName::TASK_REVIEW_CHECK->value . ' or ' . BacklogCommandName::TASK_REVIEW_APPROVE->value,
            BacklogBoard::STAGE_REJECTED => BacklogCommandName::TASK_REWORK->value,
            BacklogBoard::STAGE_APPROVED => BacklogCommandName::FEATURE_TASK_MERGE->value,
            default => '-',
        };
    }
}
