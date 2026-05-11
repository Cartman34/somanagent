<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\Enum\BacklogCommandName;
use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Service\BacklogPresenter;
use SoManAgent\Script\Service\GitService;
use SoManAgent\Script\Service\PullRequestService;

/**
 * Command for approving a feature review.
 */
final class BacklogFeatureReviewApproveCommand extends AbstractBacklogCommand
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
     * @param array<string, bool|string> $options
     * @return void
     */
    public function handle(array $commandArgs, array $options): void
    {
        $board = $this->loadBoard();
        $review = $this->loadReviewFile();
        $bodyFile = $options['body-file'] ?? null;
        if (!is_string($bodyFile)) {
            throw new \RuntimeException('Option --body-file is required.');
        }

        $feature = $this->resolveFeatureReferenceArgument($board, $commandArgs, BacklogCommandName::FEATURE_REVIEW_APPROVE->value);
        $match = $this->boardService->resolveFeature($board, $feature);
        $entry = $match->getEntry();
        $this->boardService->checkIsFeatureEntry($entry) || throw new \RuntimeException('feature-review-approve only applies to kind=feature entries.');
        $this->boardService->assertNoActiveTasksForFeature($board, $feature, BacklogCommandName::FEATURE_REVIEW_APPROVE->value);

        $stage = $this->boardService->getFeatureStage($entry);
        if ($stage !== BacklogBoard::STAGE_IN_REVIEW && $stage !== BacklogBoard::STAGE_REVIEWING) {
            throw new \RuntimeException(sprintf(
                'Feature %s must be in %s or %s to be approved.',
                $feature,
                $this->boardService->getStageLabel(BacklogBoard::STAGE_IN_REVIEW),
                $this->boardService->getStageLabel(BacklogBoard::STAGE_REVIEWING),
            ));
        }

        $branch = $entry->getBranch() ?? '';
        if ($branch === '') {
            throw new \RuntimeException("Feature {$feature} has no branch metadata.");
        }

        $tag = $this->pullRequestService->getPrTypeFromChanges($entry->getBase() ?? '', $branch);
        $title = $this->pullRequestService->buildPrTitle($tag, $entry->getText(), $entry->checkIsBlocked());
        $this->gitService->pushBranchAndAwaitVisibility($branch);
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
        $review->clearReview($feature);
        $this->saveBoard($board, BacklogCommandName::FEATURE_REVIEW_APPROVE->value);
        $this->saveReviewFile($review, BacklogCommandName::FEATURE_REVIEW_APPROVE->value);

        $this->presenter->displaySuccess(sprintf('Approved feature %s with [%s] PR title', $feature, $tag->value));
    }

    /**
     * @param array<string> $commandArgs
     */
    private function resolveFeatureReferenceArgument(BacklogBoard $board, array $commandArgs, string $command): string
    {
        if (!isset($commandArgs[0]) || trim($commandArgs[0]) === '') {
            throw new \RuntimeException(sprintf('%s requires <feature>.', $command));
        }

        return $this->boardService->normalizeFeatureSlug($commandArgs[0]);
    }
}
