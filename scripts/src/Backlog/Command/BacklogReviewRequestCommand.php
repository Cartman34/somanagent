<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Command;

use Sowapps\SoManAgent\Script\Backlog\Service\BacklogWorktreeService;
use Sowapps\SoManAgent\Script\Service\GitService;
use Sowapps\SoManAgent\Script\Backlog\Service\ReviewResumeNotifier;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogPresenter;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogBoardService;
use Sowapps\SoManAgent\Script\Backlog\Model\BacklogBoard;
use Sowapps\SoManAgent\Script\Backlog\Model\BoardEntry;
use Sowapps\SoManAgent\Script\Backlog\Enum\BacklogCommandName;
use Sowapps\SoManAgent\Script\Backlog\Enum\BacklogMetaValue;
use Sowapps\SoManAgent\Script\Backlog\Enum\BacklogEntryMetaKey;

/**
 * Unified review-request command: submits the agent's single active entry (task or feature) for review.
 *
 * Before running the mechanical review, the entry branch is rebased automatically
 * (feature on `origin/main`, task on its parent feature branch). On a rebase
 * conflict the command aborts, leaves the entry in `development`, and surfaces
 * the recovery hint without running the mechanical review. After a successful
 * rebase, `meta.base` is refreshed with the new base commit.
 */
final class BacklogReviewRequestCommand extends AbstractBacklogCommand
{
    private BacklogWorktreeService $worktreeService;

    private GitService $gitService;

    private ReviewResumeNotifier $reviewResumeNotifier;

    /**
     * @param BacklogPresenter $presenter
     * @param bool $dryRun
     * @param string $projectRoot
     * @param BacklogBoardService $boardService
     * @param BacklogWorktreeService $worktreeService
     * @param GitService $gitService
     * @param ReviewResumeNotifier $reviewResumeNotifier
     */
    public function __construct(
        BacklogPresenter $presenter,
        bool $dryRun,
        string $projectRoot,
        BacklogBoardService $boardService,
        BacklogWorktreeService $worktreeService,
        GitService $gitService,
        ReviewResumeNotifier $reviewResumeNotifier,
    ) {
        parent::__construct($presenter, $dryRun, $projectRoot, $boardService);
        $this->worktreeService = $worktreeService;
        $this->gitService = $gitService;
        $this->reviewResumeNotifier = $reviewResumeNotifier;
    }

    /**
     * @param list<string> $commandArgs
     * @param array<string, bool|string> $options
     */
    public function handle(array $commandArgs, array $options): void
    {
        $agent = $this->requireCallerAgent();

        $board = $this->loadBoard();
        $activeEntries = $this->boardService->findActiveEntriesByAgent($board, $agent);

        if ($activeEntries === []) {
            throw new \RuntimeException(
                "Developer {$agent} has no active entry.\n" .
                "Run `php scripts/backlog.php start` to start one."
            );
        }

        $entry = $activeEntries[0]->getEntry();

        if ($this->boardService->checkIsTaskEntry($entry)) {
            $this->handleTask($agent, $board, $entry);
        } else {
            $this->handleFeature($agent, $board, $entry);
        }
    }

    private function handleTask(string $agent, BacklogBoard $board, BoardEntry $entry): void
    {
        $review = $this->loadReviewFile();

        $taskWorktree = $this->worktreeService->prepareFeatureAgentWorktree($entry);
        $newBase = $this->rebaseTaskBeforeReview($entry, $taskWorktree);
        $reviewException = null;
        try {
            $this->worktreeService->runReviewScript($taskWorktree, $newBase);
        } catch (\RuntimeException $e) {
            $reviewException = $e;
        }
        $this->clearSubmitReady($entry);
        if ($reviewException !== null) {
            $this->saveBoard($board, BacklogCommandName::REVIEW_REQUEST->value);
            throw $reviewException;
        }

        $entry->setBase($newBase);
        $entry->setStage(BacklogBoard::STAGE_PENDING_REVIEW);
        $review->clearReview($this->boardService->getTaskReviewKey($entry));
        $this->saveBoard($board, BacklogCommandName::REVIEW_REQUEST->value);
        $this->saveReviewFile($review, BacklogCommandName::REVIEW_REQUEST->value);

        $this->presenter->displaySuccess(sprintf(
            'Task %s moved to %s',
            $this->boardService->getTaskReviewKey($entry),
            $this->boardService->getStageLabel(BacklogBoard::STAGE_PENDING_REVIEW),
        ));

        $this->reviewResumeNotifier->notify($board, $entry);
    }

    private function handleFeature(string $agent, BacklogBoard $board, BoardEntry $entry): void
    {
        $feature = $entry->getFeature() ?? '-';

        $this->boardService->assertNoActiveTasksForFeature($board, (string) $feature, BacklogCommandName::REVIEW_REQUEST->value);

        if ($this->boardService->getFeatureStage($entry) !== BacklogBoard::STAGE_IN_PROGRESS) {
            throw new \RuntimeException(
                "Feature {$feature} must be in " . $this->boardService->getStageLabel(BacklogBoard::STAGE_IN_PROGRESS) . '.'
            );
        }
        $featureAgent = $entry->getDeveloper();
        if ($featureAgent === null || $featureAgent === BacklogMetaValue::NONE->value) {
            throw new \RuntimeException(
                "Feature {$feature} has no assigned developer.\n" .
                "Run `php scripts/backlog.php assign --developer={$agent} {$feature}` to take ownership before submitting for review."
            );
        }
        if ($featureAgent !== $agent) {
            throw new \RuntimeException(
                "Feature {$feature} is assigned to developer {$featureAgent}, not {$agent}.\n" .
                "Details: php scripts/backlog.php status --agent={$agent}"
            );
        }

        $worktree = $this->worktreeService->prepareFeatureAgentWorktree($entry);
        $newBase = $this->rebaseFeatureBeforeReview($worktree);
        $reviewException = null;
        try {
            $this->worktreeService->runReviewScript($worktree, $newBase);
        } catch (\RuntimeException $e) {
            $reviewException = $e;
        }
        $this->clearSubmitReady($entry);
        if ($reviewException !== null) {
            $this->saveBoard($board, BacklogCommandName::REVIEW_REQUEST->value);
            throw $reviewException;
        }

        $entry->setBase($newBase);
        $entry->setStage(BacklogBoard::STAGE_PENDING_REVIEW);
        $this->saveBoard($board, BacklogCommandName::REVIEW_REQUEST->value);

        $this->presenter->displaySuccess(sprintf(
            'Feature %s moved to %s',
            $feature,
            $this->boardService->getStageLabel(BacklogBoard::STAGE_PENDING_REVIEW),
        ));

        $this->reviewResumeNotifier->notify($board, $entry);
    }

    /**
     * Removes the `submit-ready` metadata key from the entry.
     *
     * Called unconditionally before throwing on review failure and before transitioning on success.
     */
    private function clearSubmitReady(BoardEntry $entry): void
    {
        $extra = $entry->getExtraMetadata();
        if (array_key_exists(BacklogEntryMetaKey::SUBMIT_READY->value, $extra)) {
            unset($extra[BacklogEntryMetaKey::SUBMIT_READY->value]);
            $entry->setExtraMetadata($extra);
        }
    }

    /**
     * Rebase a task branch on top of its parent feature branch.
     *
     * @param BoardEntry $entry The task entry being submitted for review
     * @param string $worktree Path of the task worktree
     * @return string Commit hash of the new base (head of the parent feature branch)
     * @throws \RuntimeException When parent feature branch metadata is missing,
     *                           the parent ref does not exist, or the rebase fails
     */
    private function rebaseTaskBeforeReview(BoardEntry $entry, string $worktree): string
    {
        $featureBranch = $entry->getFeatureBranch();
        if ($featureBranch === null || $featureBranch === '') {
            throw new \RuntimeException(
                'Cannot rebase task automatically: entry metadata is missing feature-branch.'
            );
        }
        if (!$this->gitService->checkRefExists($featureBranch)) {
            throw new \RuntimeException(sprintf(
                'Cannot rebase task automatically: parent feature branch ref does not exist: %s.',
                $featureBranch,
            ));
        }

        $this->gitService->rebaseBranchOnto($worktree, $featureBranch);

        return $this->gitService->getBranchHead($featureBranch);
    }

    /**
     * Rebase a feature branch on top of `origin/main`.
     *
     * Refreshes `origin/main` first so the rebase target reflects the latest
     * remote state, without making the workflow depend on the local `main`
     * branch being in sync.
     *
     * @param string $worktree Path of the feature worktree
     * @return string Commit hash of the new base (head of `origin/main` after the fetch)
     * @throws \RuntimeException When the rebase fails
     */
    private function rebaseFeatureBeforeReview(string $worktree): string
    {
        $this->gitService->updateMainBranch();
        $target = GitService::ORIGIN_REMOTE . '/' . GitService::MAIN_BRANCH;

        $this->gitService->rebaseBranchOnto($worktree, $target);

        return $this->gitService->getBranchHead($target);
    }
}
