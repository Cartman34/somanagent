<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\BacklogBoard;
use SoManAgent\Script\Backlog\BacklogCommandName;
use SoManAgent\Script\Backlog\BacklogEntryResolver;
use SoManAgent\Script\Backlog\BacklogEntryService;
use SoManAgent\Script\Backlog\BacklogGitWorkflow;
use SoManAgent\Script\Backlog\BacklogWorktreeManager;
use SoManAgent\Script\Backlog\BoardEntry;
use SoManAgent\Script\Console;

/**
 * Command for releasing an active feature or task back to the todo section.
 */
final class BacklogFeatureReleaseCommand extends AbstractBacklogCommand
{
    private BacklogEntryResolver $entryResolver;

    private BacklogEntryService $entryService;

    private BacklogWorktreeManager $worktreeManager;

    private BacklogGitWorkflow $gitWorkflow;

    public function __construct(
        Console $console,
        bool $dryRun,
        string $projectRoot,
        BacklogEntryResolver $entryResolver,
        BacklogEntryService $entryService,
        BacklogWorktreeManager $worktreeManager,
        BacklogGitWorkflow $gitWorkflow
    ) {
        parent::__construct($console, $dryRun, $projectRoot);
        $this->entryResolver = $entryResolver;
        $this->entryService = $entryService;
        $this->worktreeManager = $worktreeManager;
        $this->gitWorkflow = $gitWorkflow;
    }

    public function handle(array $commandArgs, array $options): void
    {
        $agent = $options['agent'] ?? null;
        if (!is_string($agent)) {
            throw new \RuntimeException('Option --agent is required.');
        }
        $board = $this->loadBoard();
        $requestedTarget = BoardEntry::parseEmptyString($commandArgs[0] ?? null);
        if ($requestedTarget !== null) {
            $target = $this->entryService->normalizeFeatureSlug($requestedTarget);
            $task = $this->entryResolver->getSingleTaskForAgent($board, $agent, false);
            if ($task !== null && $task->getTask() === $target) {
                $current = $this->entryResolver->requireSingleTaskForAgent($board, $agent);
            } else {
                $current = $this->entryResolver->requireSingleFeatureForAgent($board, $agent);
                if ($current->getEntry()->getFeature() !== $target) {
                    throw new \RuntimeException(sprintf(
                        'Agent %s has no active feature or task matching %s.',
                        $agent,
                        $target,
                    ));
                }
            }
        } else {
            $current = $this->entryResolver->findTaskEntriesByAgent($board, $agent)[0] ?? $this->entryResolver->requireSingleFeatureForAgent($board, $agent);
        }
        $entry = $current->getEntry();
        $branch = $entry->getBranch();
        if ($branch === null) {
            throw new \RuntimeException('Active entry has no branch metadata.');
        }

        if ($this->entryService->featureStage($entry) !== BacklogBoard::STAGE_IN_PROGRESS) {
            throw new \RuntimeException('Active entry must be in ' . BacklogBoard::stageLabel(BacklogBoard::STAGE_IN_PROGRESS) . ' to be released.');
        }
        if (!$this->featureHasNoDevelopment($entry)) {
            throw new \RuntimeException('Active entry already has development work and cannot be released back to todo.');
        }

        if ($this->entryService->isTaskEntry($entry)) {
            $feature = $entry->getFeature() ?? '';
            $task = $entry->getTask() ?? '';
            $parent = $this->entryResolver->requireParentFeature($board, $feature);
            $todoEntries = $board->getEntries(BacklogBoard::SECTION_TODO);
            array_unshift($todoEntries, new BoardEntry(
                sprintf('[%s][%s] %s', $feature, $task, $entry->getText()),
                $entry->getExtraLines(),
            ));
            $board->setEntries(BacklogBoard::SECTION_TODO, $todoEntries);
            $this->entryService->removeActiveEntryAt($board, $current->getIndex());
            $hasFeatureContent = $this->entryService->removeTaskContribution($parent->getEntry(), $entry);
            if (!$hasFeatureContent && !$this->featureHasNoDevelopment($parent->getEntry())) {
                throw new \RuntimeException("Parent feature {$feature} still has development work and cannot be removed.");
            }
            if (!$hasFeatureContent) {
                $this->entryService->removeActiveEntryAt($board, $parent->getIndex());
                $this->gitWorkflow->deleteLocalBranchIfExists($parent->getEntry()->getBranch());
            }
            $this->saveBoard($board, BacklogCommandName::FEATURE_RELEASE->value);
            $cleaned = $this->worktreeManager->cleanupManagedWorktreesForBranch($branch, $board);
            $this->gitWorkflow->deleteLocalBranchIfExists($branch);

            $this->console->ok(sprintf('Released task %s back to todo', $task));
            if ($cleaned > 0) {
                $this->console->line(sprintf('Cleaned %d abandoned managed worktree%s.', $cleaned, $cleaned > 1 ? 's' : ''));
            }

            return;
        }

        $feature = $entry->getFeature() ?? '';
        $this->entryResolver->assertNoActiveTasksForFeature($board, $feature, BacklogCommandName::FEATURE_RELEASE->value);
        $todoEntries = $board->getEntries(BacklogBoard::SECTION_TODO);
        array_unshift($todoEntries, new BoardEntry($entry->getText(), $entry->getExtraLines()));
        $board->setEntries(BacklogBoard::SECTION_TODO, $todoEntries);
        $board->removeFeature($feature);
        $this->saveBoard($board, BacklogCommandName::FEATURE_RELEASE->value);

        $cleaned = $this->worktreeManager->cleanupManagedWorktreesForBranch($branch, $board);
        $this->gitWorkflow->deleteLocalBranchIfExists($branch);

        $this->console->ok(sprintf('Released feature %s back to todo', $feature));
        if ($cleaned > 0) {
            $this->console->line(sprintf('Cleaned %d abandoned managed worktree%s.', $cleaned, $cleaned > 1 ? 's' : ''));
        }
    }

    private function featureHasNoDevelopment(BoardEntry $entry): bool
    {
        $branch = $entry->getBranch();
        $base = $entry->getBase();
        if ($branch === null || $base === null) {
            throw new \RuntimeException('Feature metadata is incomplete: missing branch or base.');
        }

        return $this->gitWorkflow->changedFiles($base, $branch) === [];
    }
}
