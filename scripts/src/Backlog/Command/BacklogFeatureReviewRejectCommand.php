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
use SoManAgent\Script\Backlog\Service\BacklogReviewBodyFormatter;

/**
 * Command for rejecting a feature review.
 */
final class BacklogFeatureReviewRejectCommand extends AbstractBacklogCommand
{
    private BacklogReviewBodyFormatter $reviewBodyFormatter;

    public function __construct(
        BacklogPresenter $presenter,
        bool $dryRun,
        string $projectRoot,
        BacklogBoardService $boardService,
        BacklogReviewBodyFormatter $reviewBodyFormatter
    ) {
        parent::__construct($presenter, $dryRun, $projectRoot, $boardService);
        $this->reviewBodyFormatter = $reviewBodyFormatter;
    }

    public function handle(array $commandArgs, array $options): void
    {
        $bodyFile = $options['body-file'] ?? null;
        if (!is_string($bodyFile)) {
            throw new \RuntimeException('Option --body-file is required.');
        }

        $board = $this->loadBoard();
        $review = $this->loadReviewFile();
        $feature = $this->resolveFeatureReferenceArgument($board, $commandArgs, BacklogCommandName::FEATURE_REVIEW_REJECT->value);
        $match = $this->boardService->resolveFeature($board, $feature);
        $entry = $match->getEntry();

        if ($this->boardService->getFeatureStage($entry) !== BacklogBoard::STAGE_IN_REVIEW) {
            throw new \RuntimeException(sprintf(
                'Feature %s must be in %s to be rejected.',
                $feature,
                $this->boardService->getStageLabel(BacklogBoard::STAGE_IN_REVIEW),
            ));
        }

        $entry->setStage(BacklogBoard::STAGE_REJECTED);
        $review->setReview($feature, $this->reviewBodyFormatter->fromFile($bodyFile));
        $this->saveBoard($board, BacklogCommandName::FEATURE_REVIEW_REJECT->value);
        $this->saveReviewFile($review, BacklogCommandName::FEATURE_REVIEW_REJECT->value);

        $this->presenter->displaySuccess(sprintf(
            'Rejected feature %s, moved to %s',
            $feature,
            $this->boardService->getStageLabel(BacklogBoard::STAGE_REJECTED),
        ));
    }

    private function resolveFeatureReferenceArgument(BacklogBoard $board, array $commandArgs, string $command): string
    {
        if (!isset($commandArgs[0]) || trim($commandArgs[0]) === '') {
            throw new \RuntimeException(sprintf('%s requires <feature>.', $command));
        }

        return $this->boardService->normalizeFeatureSlug($commandArgs[0]);
    }
}
