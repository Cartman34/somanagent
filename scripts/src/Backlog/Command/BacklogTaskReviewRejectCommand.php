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
        $match = $this->boardService->resolveTaskByReference($board, $commandArgs[0] ?? '', BacklogCommandName::TASK_REVIEW_REJECT->value);
        $entry = $match->getEntry();

        if ($this->boardService->getFeatureStage($entry) !== BacklogBoard::STAGE_IN_REVIEW) {
            throw new \RuntimeException(sprintf(
                'Task %s must be in %s to be rejected.',
                $this->boardService->getTaskReviewKey($entry),
                $this->boardService->getStageLabel(BacklogBoard::STAGE_IN_REVIEW),
            ));
        }

        $entry->setStage(BacklogBoard::STAGE_REJECTED);
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
