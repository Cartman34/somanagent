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
use RuntimeException;

/**
 * Command for approving a task review.
 */
final class BacklogTaskReviewApproveCommand extends AbstractBacklogCommand
{
    /**
     * @param BacklogPresenter $presenter
     * @param bool $dryRun
     * @param string $projectRoot
     * @param BacklogBoardService $boardService
     */
    public function __construct(
        BacklogPresenter $presenter,
        bool $dryRun,
        string $projectRoot,
        BacklogBoardService $boardService
    ) {
        parent::__construct($presenter, $dryRun, $projectRoot, $boardService);
    }

    /**
     * @param list<string> $commandArgs
     * @param array<string, bool|string> $options
     */
    public function handle(array $commandArgs, array $options): void
    {
        $board = $this->loadBoard();
        $review = $this->loadReviewFile();
        $reference = $commandArgs[0] ?? '';
        $match = $this->boardService->resolveTaskByReference($board, $reference, BacklogCommandName::TASK_REVIEW_APPROVE->value);
        $entry = $match->getEntry();

        $stage = $this->boardService->getFeatureStage($entry);
        if ($stage !== BacklogBoard::STAGE_IN_REVIEW && $stage !== BacklogBoard::STAGE_REVIEWING) {
            throw new RuntimeException(sprintf(
                'Task %s must be in %s or %s to be approved.',
                $this->boardService->getTaskReviewKey($entry),
                $this->boardService->getStageLabel(BacklogBoard::STAGE_IN_REVIEW),
                $this->boardService->getStageLabel(BacklogBoard::STAGE_REVIEWING),
            ));
        }

        $entry->setStage(BacklogBoard::STAGE_APPROVED);
        $entry->setReviewer(null);
        $review->clearReview($this->boardService->getTaskReviewKey($entry));
        $this->saveBoard($board, BacklogCommandName::TASK_REVIEW_APPROVE->value);
        $this->saveReviewFile($review, BacklogCommandName::TASK_REVIEW_APPROVE->value);

        $this->presenter->displaySuccess(sprintf('Approved task %s', $this->boardService->getTaskReviewKey($entry)));
    }
}
