<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\Enum\BacklogCommandName;
use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Model\BoardEntry;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Service\BacklogPresenter;
use SoManAgent\Script\Backlog\Service\BacklogWorktreeService;
use SoManAgent\Script\Service\GitService;

/**
 * Command for merging a task into its parent feature locally.
 */
final class BacklogFeatureTaskMergeCommand extends AbstractBacklogCommand
{
    private BacklogWorktreeService $worktreeService;

    private GitService $gitService;

    /**
     * @param BacklogPresenter $presenter
     * @param bool $dryRun
     * @param string $projectRoot
     * @param BacklogBoardService $boardService
     * @param BacklogWorktreeService $worktreeService
     * @param GitService $gitService
     */
    public function __construct(
        BacklogPresenter $presenter,
        bool $dryRun,
        string $projectRoot,
        BacklogBoardService $boardService,
        BacklogWorktreeService $worktreeService,
        GitService $gitService
    ) {
        parent::__construct($presenter, $dryRun, $projectRoot, $boardService);
        $this->worktreeService = $worktreeService;
        $this->gitService = $gitService;
    }

    /**
     * @param list<string> $commandArgs
     * @param array<string, bool|string> $options
     * @return void
     */
    public function handle(array $commandArgs, array $options): void
    {
        throw new \RuntimeException(
            'feature-task-merge is no longer a public command. Use: php scripts/backlog.php entry-merge <entry-ref> --agent=<reviewer>',
        );
    }

    /**
     * @param list<string> $commandArgs
     * @param array<string, bool|string> $options
     * @return void
     */
    public function performMerge(array $commandArgs, array $options): void
    {
        $board = $this->loadBoard();
        $review = $this->loadReviewFile();
        $agentOption = $options['agent'] ?? null;
        $agent = is_string($agentOption) ? $this->boardService->sanitizeString($agentOption) : null;
        if ($agent !== null) {
            $match = isset($commandArgs[0])
                ? $this->boardService->resolveTaskByReference($board, $commandArgs[0], BacklogCommandName::FEATURE_TASK_MERGE->value)
                : $this->boardService->resolveSingleTaskForAgent($board, $agent);
        } else {
            if ($this->boardService->sanitizeString($commandArgs[0] ?? null) === null) {
                throw new \RuntimeException('feature-task-merge requires a full <entry-ref> when used without --agent.');
            }

            $match = $this->boardService->resolveTaskByReference($board, $commandArgs[0], BacklogCommandName::FEATURE_TASK_MERGE->value);
        }
        $entry = $match->getEntry();
        $this->boardService->checkIsTaskEntry($entry) || throw new \RuntimeException('feature-task-merge only applies to kind=task entries.');
        if ($agent !== null && $entry->getAgent() !== $agent) {
            throw new \RuntimeException('feature-task-merge requires the task to be assigned to the provided agent.');
        }
        $taskAgent = $entry->getAgent() ?? '';

        $feature = $entry->getFeature() ?? '';
        $task = $entry->getTask() ?? '';
        $featureBranch = $entry->getFeatureBranch() ?? '';
        $taskBranch = $entry->getBranch() ?? '';
        $parent = $this->boardService->resolveFeature($board, $feature);
        $this->worktreeService->assertBranchHasNoDirtyManagedWorktree($taskBranch);
        $mergeContext = $this->worktreeService->prepareFeatureMergeWorktree($featureBranch, $feature);

        try {
            $this->gitService->mergeBranchInPath(
                $mergeContext['path'],
                $taskBranch,
                sprintf('Merge task %s into feature %s', $task, $feature),
            );
        } catch (\Throwable $exception) {
            if ($mergeContext['temporary']) {
                $this->worktreeService->removeTemporaryMergeWorktree($mergeContext['path']);
            }

            throw $exception;
        }

        $this->boardService->removeActiveEntryAt($board, $match->getIndex());
        $this->invalidateFeatureReviewState($parent->getEntry());
        $review->clearReview($this->boardService->getTaskReviewKey($entry));
        $this->saveBoard($board, BacklogCommandName::FEATURE_TASK_MERGE->value);
        $this->saveReviewFile($review, BacklogCommandName::FEATURE_TASK_MERGE->value);

        if ($mergeContext['temporary']) {
            $this->worktreeService->removeTemporaryMergeWorktree($mergeContext['path']);
        }

        $this->worktreeService->cleanupMergedTaskWorktree($taskAgent, $taskBranch, $board);

        $this->gitService->deleteLocalBranch($taskBranch);

        $this->presenter->displaySuccess(sprintf('Merged task %s into feature %s locally', $task, $feature));
    }

    private function invalidateFeatureReviewState(BoardEntry $featureEntry): void
    {
        if ($this->boardService->getFeatureStage($featureEntry) !== BacklogBoard::STAGE_IN_PROGRESS) {
            $featureEntry->setStage(BacklogBoard::STAGE_IN_PROGRESS);
        }
    }
}
