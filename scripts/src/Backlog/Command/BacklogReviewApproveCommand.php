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
use SoManAgent\Script\Service\GitService;
use SoManAgent\Script\Service\PullRequestService;

/**
 * Reviewer command that approves a feature or task review.
 *
 * Carries the feature and task logic directly. Short task references (bare task slug without the
 * parent feature) are refused. `--body-file` is required for feature approvals and rejected for task
 * approvals.
 */
final class BacklogReviewApproveCommand extends AbstractBacklogCommand
{
    private GitService $gitService;
    private PullRequestService $pullRequestService;

    /**
     * @param BacklogPresenter $presenter
     * @param bool $dryRun
     * @param string $projectRoot
     * @param BacklogBoardService $boardService
     * @param GitService $gitService
     * @param PullRequestService $pullRequestService
     */
    public function __construct(
        BacklogPresenter $presenter,
        bool $dryRun,
        string $projectRoot,
        BacklogBoardService $boardService,
        GitService $gitService,
        PullRequestService $pullRequestService
    ) {
        parent::__construct($presenter, $dryRun, $projectRoot, $boardService);
        $this->gitService = $gitService;
        $this->pullRequestService = $pullRequestService;
    }

    /**
     * @param list<string> $commandArgs
     * @param array<string, bool|string|array<bool|string>> $options
     */
    public function handle(array $commandArgs, array $options): void
    {
        $this->requireCallerAgent();

        $reference = trim($commandArgs[0] ?? '');
        if ($reference === '') {
            throw new \RuntimeException('review-approve requires <entry-ref>.');
        }

        $board = $this->loadBoard();
        $review = $this->loadReviewFile();

        if (str_contains($reference, '/')) {
            if (array_key_exists('body-file', $options)) {
                throw new \RuntimeException('review-approve does not accept --body-file for task approvals.');
            }

            $match = $this->boardService->resolveTaskByReference($board, $reference, BacklogCommandName::REVIEW_APPROVE->value);
            $entry = $match->getEntry();
            $this->assertStageAllowsApprove($entry);

            $reviewKey = $this->boardService->getTaskReviewKey($entry);
            $entry->setStage(BacklogBoard::STAGE_APPROVED);
            $entry->setReviewer(null);
            $review->clearReview($reviewKey);
            $this->saveBoard($board, BacklogCommandName::REVIEW_APPROVE->value);
            $this->saveReviewFile($review, BacklogCommandName::REVIEW_APPROVE->value);

            $this->presenter->displaySuccess(sprintf('Approved task %s', $reviewKey));

            return;
        }

        $bodyFile = $options['body-file'] ?? null;
        if (!is_string($bodyFile) || $bodyFile === '') {
            throw new \RuntimeException('review-approve requires --body-file=<path> for feature approvals.');
        }

        $slug = $this->boardService->normalizeFeatureSlug($reference);
        if ($this->boardService->findParentFeatureEntry($board, $slug) === null) {
            $taskMatches = $this->boardService->findTaskEntriesByTaskSlug($board, $slug);
            if ($taskMatches !== []) {
                throw new \RuntimeException(sprintf(
                    'review-approve refuses short task reference `%s`; use `<entry-ref>` instead.',
                    $slug,
                ));
            }
        }

        $match = $this->boardService->resolveFeature($board, $slug);
        $entry = $match->getEntry();
        $this->boardService->checkIsFeatureEntry($entry) || throw new \RuntimeException('review-approve only applies to kind=feature entries.');
        $this->boardService->assertNoActiveTasksForFeature($board, $slug, BacklogCommandName::REVIEW_APPROVE->value);
        $this->boardService->assertNoQueuedTasksForFeature($board, $slug, BacklogCommandName::REVIEW_APPROVE->value);
        $this->assertStageAllowsApprove($entry);

        $branch = $entry->getBranch() ?? '';
        if ($branch === '') {
            throw new \RuntimeException("Feature {$slug} has no branch metadata.");
        }

        $tag = $this->pullRequestService->getPrTypeFromChanges($entry->getBase() ?? '', $branch);
        $title = $this->pullRequestService->buildPrTitle($tag, $entry->getText(), $entry->checkIsBlocked());
        $this->gitService->pushBranchSafely($branch);
        $this->pullRequestService->createOrUpdatePr($branch, $title, $bodyFile);
        $prNumber = $this->pullRequestService->findPrNumberByBranch($branch);
        if ($prNumber === null && !$this->dryRun) {
            throw new \RuntimeException("No open PR found for branch {$branch} after approval update.");
        }

        if ($prNumber !== null) {
            $entry->setPr((string) $prNumber);
        }
        $entry->setStage(BacklogBoard::STAGE_APPROVED);
        $entry->setReviewer(null);
        $review->clearReview($slug);
        $this->saveBoard($board, BacklogCommandName::REVIEW_APPROVE->value);
        $this->saveReviewFile($review, BacklogCommandName::REVIEW_APPROVE->value);

        $this->presenter->displaySuccess(sprintf('Approved feature %s with [%s] PR title', $slug, $tag->value));
    }

    /**
     * Refuses unless the entry is in review or reviewing stage.
     */
    private function assertStageAllowsApprove(BoardEntry $entry): void
    {
        $stage = $this->boardService->getFeatureStage($entry);
        if ($stage === BacklogBoard::STAGE_IN_REVIEW || $stage === BacklogBoard::STAGE_REVIEWING) {
            return;
        }

        $label = $this->boardService->checkIsTaskEntry($entry)
            ? sprintf('Task %s', $this->boardService->getTaskReviewKey($entry))
            : sprintf('Feature %s', $entry->getFeature() ?? '');

        throw new \RuntimeException(sprintf(
            '%s must be in %s or %s to be approved.',
            $label,
            $this->boardService->getStageLabel(BacklogBoard::STAGE_IN_REVIEW),
            $this->boardService->getStageLabel(BacklogBoard::STAGE_REVIEWING),
        ));
    }
}
