<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\Enum\BacklogCliOption;
use SoManAgent\Script\Backlog\Enum\BacklogCommandName;
use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Model\BoardEntry;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Service\BacklogPresenter;
use SoManAgent\Script\Backlog\Service\BacklogReviewBodyFormatter;
use SoManAgent\Script\Backlog\Service\BodyFilePathResolver;

/**
 * Reviewer command that rejects a feature or task review and records reviewer blockers.
 *
 * Carries the feature and task logic directly. Short task references (bare task slug without the
 * parent feature) are refused.
 */
final class BacklogReviewRejectCommand extends AbstractBacklogCommand
{
    private BacklogReviewBodyFormatter $reviewBodyFormatter;

    private BodyFilePathResolver $bodyFilePathResolver;

    /**
     * @param BacklogPresenter $presenter
     * @param bool $dryRun
     * @param string $projectRoot
     * @param BacklogBoardService $boardService
     * @param BacklogReviewBodyFormatter $reviewBodyFormatter
     * @param BodyFilePathResolver $bodyFilePathResolver
     */
    public function __construct(
        BacklogPresenter $presenter,
        bool $dryRun,
        string $projectRoot,
        BacklogBoardService $boardService,
        BacklogReviewBodyFormatter $reviewBodyFormatter,
        BodyFilePathResolver $bodyFilePathResolver
    ) {
        parent::__construct($presenter, $dryRun, $projectRoot, $boardService);
        $this->reviewBodyFormatter = $reviewBodyFormatter;
        $this->bodyFilePathResolver = $bodyFilePathResolver;
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
            throw new \RuntimeException('review-reject requires <entry-ref>.');
        }

        $bodyFile = $options[BacklogCliOption::BODY_FILE->value] ?? null;
        if (!is_string($bodyFile) || $bodyFile === '') {
            throw new \RuntimeException('review-reject requires --body-file=<path>.');
        }

        $board = $this->loadBoard();
        $review = $this->loadReviewFile();

        if (str_contains($reference, '/')) {
            $match = $this->boardService->resolveTaskByReference($board, $reference, BacklogCommandName::REVIEW_REJECT->value);
            $entry = $match->getEntry();
            $this->assertStageAllowsReviewMutation($entry, 'rejected');

            $reviewKey = $this->boardService->getTaskReviewKey($entry);
            $entry->setStage(BacklogBoard::STAGE_REJECTED);
            $entry->setReviewer(null);
            $review->setReview($reviewKey, $this->reviewBodyFormatter->fromFile($this->bodyFilePathResolver->resolveForEntry($bodyFile, $reference)));
            $this->saveBoard($board, BacklogCommandName::REVIEW_REJECT->value);
            $this->saveReviewFile($review, BacklogCommandName::REVIEW_REJECT->value);

            $this->presenter->displaySuccess(sprintf(
                'Rejected task %s, moved to %s',
                $reviewKey,
                $this->boardService->getStageLabel(BacklogBoard::STAGE_REJECTED),
            ));

            return;
        }

        $slug = $this->boardService->normalizeFeatureSlug($reference);
        if ($this->boardService->findParentFeatureEntry($board, $slug) === null) {
            $taskMatches = $this->boardService->findTaskEntriesByTaskSlug($board, $slug);
            if ($taskMatches !== []) {
                throw new \RuntimeException(sprintf(
                    'review-reject refuses short task reference `%s`; use `<entry-ref>` instead.',
                    $slug,
                ));
            }
        }

        $match = $this->boardService->resolveFeature($board, $slug);
        $entry = $match->getEntry();
        $this->assertStageAllowsReviewMutation($entry, 'rejected');

        $entry->setStage(BacklogBoard::STAGE_REJECTED);
        $entry->setReviewer(null);
        $review->setReview($slug, $this->reviewBodyFormatter->fromFile($this->bodyFilePathResolver->resolveForEntry($bodyFile, $slug)));
        $this->saveBoard($board, BacklogCommandName::REVIEW_REJECT->value);
        $this->saveReviewFile($review, BacklogCommandName::REVIEW_REJECT->value);

        $this->presenter->displaySuccess(sprintf(
            'Rejected feature %s, moved to %s',
            $slug,
            $this->boardService->getStageLabel(BacklogBoard::STAGE_REJECTED),
        ));
    }

    /**
     * Refuses unless the entry is in review or reviewing stage.
     */
    private function assertStageAllowsReviewMutation(BoardEntry $entry, string $action): void
    {
        $stage = $this->boardService->getFeatureStage($entry);
        if ($stage === BacklogBoard::STAGE_IN_REVIEW || $stage === BacklogBoard::STAGE_REVIEWING) {
            return;
        }

        $label = $this->boardService->checkIsTaskEntry($entry)
            ? sprintf('Task %s', $this->boardService->getTaskReviewKey($entry))
            : sprintf('Feature %s', $entry->getFeature() ?? '');

        throw new \RuntimeException(sprintf(
            '%s must be in %s or %s to be %s.',
            $label,
            $this->boardService->getStageLabel(BacklogBoard::STAGE_IN_REVIEW),
            $this->boardService->getStageLabel(BacklogBoard::STAGE_REVIEWING),
            $action,
        ));
    }
}
