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
 * Command for merging a task into its parent feature locally.
 */
final class BacklogFeatureTaskMergeCommand extends AbstractBacklogCommand
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
        $board = $this->loadBoard();
        $review = $this->loadReviewFile();
        $agent = BoardEntry::parseEmptyString((string) ($options['agent'] ?? ''));
        if ($agent !== null) {
            $match = isset($commandArgs[0])
                ? $this->entryResolver->requireTaskByReference($board, $commandArgs[0], BacklogCommandName::FEATURE_TASK_MERGE->value)
                : $this->entryResolver->requireSingleTaskForAgent($board, $agent);
        } else {
            if (BoardEntry::parseEmptyString($commandArgs[0] ?? null) === null) {
                throw new \RuntimeException('feature-task-merge requires <feature/task> when used without --agent.');
            }

            $match = $this->entryResolver->requireTaskByReference($board, $commandArgs[0], BacklogCommandName::FEATURE_TASK_MERGE->value);
        }
        if ($match === null) {
            throw new \RuntimeException('No task available for feature-task-merge.');
        }

        $entry = $match->getEntry();
        $this->entryService->assertTaskEntry($entry, BacklogCommandName::FEATURE_TASK_MERGE->value);
        if ($agent !== null && $entry->getAgent() !== $agent) {
            throw new \RuntimeException('feature-task-merge requires the task to be assigned to the provided agent.');
        }
        $taskAgent = $entry->getAgent() ?? '';

        $feature = $entry->getFeature() ?? '';
        $task = $entry->getTask() ?? '';
        $featureBranch = $entry->getFeatureBranch() ?? '';
        $taskBranch = $entry->getBranch() ?? '';
        $parent = $this->entryResolver->requireParentFeature($board, $feature);
        $taskWorktree = $this->worktreeManager->prepareFeatureAgentWorktree($entry);
        $this->worktreeManager->runReviewScript($taskWorktree, $entry->getBase());
        $this->worktreeManager->ensureBranchHasNoDirtyManagedWorktree($taskBranch);
        $mergeContext = $this->worktreeManager->prepareFeatureMergeWorktree($featureBranch, $feature);

        try {
            $this->gitWorkflow->mergeBranchInPath(
                $mergeContext['path'],
                $taskBranch,
                sprintf('Merge task %s into feature %s', $task, $feature),
            );
        } catch (\Throwable $exception) {
            if ($mergeContext['temporary']) {
                $this->worktreeManager->removeTemporaryMergeWorktree($mergeContext['path']);
            }

            throw $exception;
        }

        $this->entryService->removeActiveEntryAt($board, $match->getIndex());
        if ($parent->getEntry()->getAgent() === null) {
            $parent->getEntry()->setAgent($taskAgent);
        }
        $this->entryService->invalidateFeatureReviewState($parent->getEntry());
        $review->clearReview($this->entryService->taskReviewKey($entry));
        $this->saveBoard($board, BacklogCommandName::FEATURE_TASK_MERGE->value);
        $this->saveReviewFile($review, BacklogCommandName::FEATURE_TASK_MERGE->value);

        if ($mergeContext['temporary']) {
            $this->worktreeManager->removeTemporaryMergeWorktree($mergeContext['path']);
        }

        $this->worktreeManager->cleanupMergedTaskWorktree($taskAgent, $taskBranch, $board);

        $this->gitWorkflow->deleteLocalBranchIfExists($taskBranch);

        $this->console->ok(sprintf('Merged task %s into feature %s locally', $task, $feature));
    }
}
