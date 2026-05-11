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
 * Command for rejecting a task review.
 */
final class BacklogTaskReviewRejectCommand extends AbstractBacklogCommand
{
    private BacklogReviewBodyFormatter $reviewBodyFormatter;

    /**
     * @param BacklogPresenter $presenter
     * @param bool $dryRun
     * @param string $projectRoot
     * @param BacklogBoardService $boardService
     * @param BacklogReviewBodyFormatter $reviewBodyFormatter
     */
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

    /**
     * @param list<string> $commandArgs
     * @param array<string, bool|string> $options
     */
    public function handle(array $commandArgs, array $options): void
    {
        $bodyFile = $options['body-file'] ?? null;
        if (!is_string($bodyFile)) {
            throw new \RuntimeException('Option --body-file is required.');
        }

        $board = $this->loadBoard();
        $review = $this->loadReviewFile();
        $match = $this->boardService->resolveTaskByReference($board, $commandArgs[0] ?? '', BacklogCommandName::TASK_REVIEW_REJECT->value);
        $entry = $match->getEntry();

        $stage = $this->boardService->getFeatureStage($entry);
        if ($stage !== BacklogBoard::STAGE_IN_REVIEW && $stage !== BacklogBoard::STAGE_REVIEWING) {
            throw new \RuntimeException(sprintf(
                'Task %s must be in %s or %s to be rejected.',
                $this->boardService->getTaskReviewKey($entry),
                $this->boardService->getStageLabel(BacklogBoard::STAGE_IN_REVIEW),
                $this->boardService->getStageLabel(BacklogBoard::STAGE_REVIEWING),
            ));
        }

        $entry->setStage(BacklogBoard::STAGE_REJECTED);
        $entry->setReviewer(null);
        $review->setReview($this->boardService->getTaskReviewKey($entry), $this->reviewBodyFormatter->fromFile($bodyFile));
        $this->saveBoard($board, BacklogCommandName::TASK_REVIEW_REJECT->value);
        $this->saveReviewFile($review, BacklogCommandName::TASK_REVIEW_REJECT->value);

        $this->presenter->displaySuccess(sprintf(
            'Rejected task %s, moved to %s',
            $this->boardService->getTaskReviewKey($entry),
            $this->boardService->getStageLabel(BacklogBoard::STAGE_REJECTED),
        ));
    }
}
