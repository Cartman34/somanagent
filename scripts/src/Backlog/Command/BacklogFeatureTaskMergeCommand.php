<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\Enum\BacklogCliOption;
use SoManAgent\Script\Backlog\Enum\BacklogCommandName;
use SoManAgent\Script\Backlog\Enum\BacklogEntryMetaKey;
use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Model\BoardEntry;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Service\BacklogPresenter;
use SoManAgent\Script\Backlog\Service\BacklogWorktreeService;
use SoManAgent\Script\Backlog\Service\PostMergeSessionStopper;
use SoManAgent\Script\Service\GitService;

/**
 * Command for merging a task into its parent feature locally.
 */
final class BacklogFeatureTaskMergeCommand extends AbstractBacklogCommand
{
    private BacklogWorktreeService $worktreeService;

    private GitService $gitService;

    private PostMergeSessionStopper $sessionStopper;

    /**
     * @param BacklogPresenter $presenter
     * @param bool $dryRun
     * @param string $projectRoot
     * @param BacklogBoardService $boardService
     * @param BacklogWorktreeService $worktreeService
     * @param GitService $gitService
     * @param PostMergeSessionStopper $sessionStopper
     */
    public function __construct(
        BacklogPresenter $presenter,
        bool $dryRun,
        string $projectRoot,
        BacklogBoardService $boardService,
        BacklogWorktreeService $worktreeService,
        GitService $gitService,
        PostMergeSessionStopper $sessionStopper
    ) {
        parent::__construct($presenter, $dryRun, $projectRoot, $boardService);
        $this->worktreeService = $worktreeService;
        $this->gitService = $gitService;
        $this->sessionStopper = $sessionStopper;
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
        $agentOption = $options[BacklogCliOption::AGENT->value] ?? null;
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
        if ($agent !== null && $entry->getDeveloper() !== $agent) {
            throw new \RuntimeException('feature-task-merge requires the task to be assigned to the provided agent.');
        }
        $devCode = $entry->getDeveloper();
        $reviewerCode = $entry->getReviewer();
        $taskAgent = $devCode ?? '';

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

        $this->propagateDependencyUpdate($entry, $parent->getEntry());
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

        $this->sessionStopper->stopSessions($devCode, $reviewerCode);
    }

    private function invalidateFeatureReviewState(BoardEntry $featureEntry): void
    {
        if ($this->boardService->getFeatureStage($featureEntry) !== BacklogBoard::STAGE_IN_PROGRESS) {
            $featureEntry->setStage(BacklogBoard::STAGE_IN_PROGRESS);
        }
    }

    /**
     * Merges the task dependency-update scopes into the parent feature (union, no duplicates).
     */
    private function propagateDependencyUpdate(BoardEntry $taskEntry, BoardEntry $featureEntry): void
    {
        $taskMeta = $taskEntry->getExtraMetadata();
        $taskScopes = $taskMeta[BacklogEntryMetaKey::DEPENDENCY_UPDATE->value] ?? '';
        if ($taskScopes === '') {
            return;
        }

        $featureMeta = $featureEntry->getExtraMetadata();
        $featureScopes = $featureMeta[BacklogEntryMetaKey::DEPENDENCY_UPDATE->value] ?? '';

        $merged = $this->mergeScopesCsv($featureScopes, $taskScopes);
        $featureMeta[BacklogEntryMetaKey::DEPENDENCY_UPDATE->value] = $merged;
        $featureEntry->setExtraMetadata($featureMeta);
    }

    /**
     * Returns a deduplicated CSV union of two scope CSV strings.
     */
    private function mergeScopesCsv(string $a, string $b): string
    {
        $scopesA = $a !== '' ? array_map('trim', explode(',', $a)) : [];
        $scopesB = $b !== '' ? array_map('trim', explode(',', $b)) : [];
        $merged = array_values(array_unique(array_merge($scopesA, $scopesB)));

        return implode(',', $merged);
    }
}
