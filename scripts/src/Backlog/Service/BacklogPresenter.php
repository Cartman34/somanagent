<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Service;

use SoManAgent\Script\Backlog\Model\BoardEntry;
use SoManAgent\Script\Backlog\Model\WorktreeClassification;
use SoManAgent\Script\Backlog\Model\ManagedWorktree;
use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Enum\WorktreeState;
use SoManAgent\Script\Backlog\Enum\WorktreeAction;
use SoManAgent\Script\Backlog\Enum\BacklogMetaValue;
use SoManAgent\Script\Backlog\Enum\BacklogCommandName;
use SoManAgent\Script\Client\ConsoleClient;
use SoManAgent\Script\Console;

/**
 * Handles all visual presentation logic for backlog commands.
 */
final class BacklogPresenter
{
    private Console $console;

    private ConsoleClient $consoleClient;

    private BacklogBoardService $boardService;

    public function __construct(Console $console, ConsoleClient $consoleClient, BacklogBoardService $boardService)
    {
        $this->console = $console;
        $this->consoleClient = $consoleClient;
        $this->boardService = $boardService;
    }

    public function displaySuccess(string $message): void
    {
        $this->console->ok($message);
    }

    public function displayInfo(string $message): void
    {
        $this->console->info($message);
    }

    public function displayLine(string $message): void
    {
        $this->console->line($message);
    }

    public function displayEntryStatus(BoardEntry $entry): void
    {
        $stage = $this->boardService->getFeatureStage($entry);
        $this->console->line('Kind: ' . $this->boardService->getEntryKind($entry));
        if ($this->boardService->checkIsTaskEntry($entry)) {
            $this->console->line('Feature: ' . ($entry->getFeature() ?? '-'));
            $this->console->line('Task: ' . ($entry->getTask() ?? '-'));
            $this->console->line('Ref: ' . $this->boardService->getTaskReviewKey($entry));
            $this->console->line('Feature Branch: ' . ($entry->getFeatureBranch() ?? '-'));
        } else {
            $this->console->line('Feature: ' . ($entry->getFeature() ?? '-'));
        }
        $this->console->line('Branch: ' . ($entry->getBranch() ?? '-'));
        $this->console->line('Base: ' . ($entry->getBase() ?? '-'));
        $this->console->line('Stage: ' . $this->boardService->getStageLabel($stage));
        $this->console->line('PR: ' . $this->describePrStatus($entry));
        $this->console->line('Summary: ' . $entry->getText());
        $this->displayEntryDetails($entry);
        $this->console->line('Next: ' . $this->nextStepForEntry($entry, $stage));
        $this->console->line('Blocker: ' . ($entry->checkIsBlocked() ? 'blocked' : '-'));
    }

    public function displayEntryDetails(BoardEntry $entry): void
    {
        $extraLines = $entry->getExtraLines();
        if ($extraLines !== []) {
            $this->console->line('Details:');
            foreach ($extraLines as $line) {
                $this->console->line($line);
            }
        }
    }

    public function displayWorktreeList(WorktreeClassification $classification): void
    {
        if ($classification->getManaged() === [] && $classification->getExternal() === []) {
            $this->console->line('No worktree to report.');

            return;
        }

        if ($classification->getManaged() !== []) {
            $this->console->line('[Managed worktrees]');
            foreach ($classification->getManaged() as $item) {
                $parts = [
                    $this->consoleClient->toRelativeProjectPath($item->getPath()),
                    'state=' . $item->getState()->value,
                    'branch=' . ($item->getBranch() ?? '-'),
                    'feature=' . ($item->getFeature() ?? '-'),
                    'agent=' . ($item->getAgent() ?? '-'),
                    'action=' . $item->getAction()->value,
                ];
                $this->console->line('- ' . implode(' ', $parts));
            }
        }

        if ($classification->getExternal() !== []) {
            $this->console->line('[External worktrees]');
            foreach ($classification->getExternal() as $item) {
                $parts = [
                    $item->getPath(),
                    'branch=' . ($item->getBranch() ?? '-'),
                    'action=' . $item->getAction()->value,
                ];
                $this->console->line('- ' . implode(' ', $parts));
            }
            $this->console->line('Manual cleanup: verify each external worktree is disposable, then use `git worktree remove <path>` or `git worktree prune` when only metadata remains.');
        }
    }

    public function displayManagedWorktreeStatus(ManagedWorktree $worktree): void
    {
        $this->console->line('State:  ' . $this->statusWorktreeStateLabel($worktree->getState()));
        $this->console->line('Path:   ' . $this->consoleClient->toRelativeProjectPath($worktree->getPath()));
        $this->console->line('Branch: ' . ($worktree->getBranch() ?? '-'));
        $this->console->line('Action: ' . $worktree->getAction()->value);
    }

    public function displayStageHeader(string $stage): void
    {
        $this->console->line('[' . $this->boardService->getStageLabel($stage) . ']');
    }

    public function displayEntryLine(BoardEntry $entry): void
    {
        $parts = [
            $entry->getFeature() ?? '-',
            'agent=' . ($entry->getAgent() ?? '-'),
            'pr=' . $this->describePrStatus($entry),
        ];
        if ($entry->checkIsBlocked()) {
            $parts[] = 'blocked=' . BacklogMetaValue::YES->value;
        }
        $this->console->line('- ' . implode(' ', $parts));
    }

    public function displayTodoEntryLine(BoardEntry $entry): void
    {
        $parts = [
            'agent=' . ($entry->getAgent() ?? '-'),
        ];
        if ($this->boardService->checkIsTaskEntry($entry)) {
            $parts[] = 'task=' . ($entry->getTask() ?? '-');
            $parts[] = 'feature-branch=' . ($entry->getFeatureBranch() ?? '-');
        }
        if ($entry->checkIsBlocked()) {
            $parts[] = 'blocked=' . BacklogMetaValue::YES->value;
        }
        $this->console->line('- ' . implode(' ', $parts));
    }

    private function statusWorktreeStateLabel(WorktreeState $state): string
    {
        return match ($state) {
            WorktreeState::ACTIVE => WorktreeAction::CLEAN->value,
            WorktreeState::DIRTY => WorktreeState::DIRTY->value,
            WorktreeState::BLOCKED => 'inconsistent',
            WorktreeState::DETACHED_MANAGED => 'detached',
            WorktreeState::PRUNABLE => WorktreeState::PRUNABLE->value,
            WorktreeState::ORPHAN => WorktreeState::ORPHAN->value,
        };
    }

    private function describePrStatus(BoardEntry $entry): string
    {
        $pr = $entry->getPr();
        if ($pr === null || $pr === BacklogMetaValue::NONE->value) {
            return BacklogMetaValue::NONE->value;
        }

        return '#' . $pr;
    }

    private function nextStepForEntry(BoardEntry $entry, string $stage): string
    {
        return $this->boardService->checkIsTaskEntry($entry)
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
