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
use SoManAgent\Script\Backlog\Service\BacklogWorktreeService;

/**
 * Command for requesting a review for a task.
 */
final class BacklogTaskReviewRequestCommand extends AbstractBacklogCommand
{
    private BacklogWorktreeService $worktreeService;

    public function __construct(
        BacklogPresenter $presenter,
        bool $dryRun,
        string $projectRoot,
        BacklogBoardService $boardService,
        BacklogWorktreeService $worktreeService
    ) {
        parent::__construct($presenter, $dryRun, $projectRoot, $boardService);
        $this->worktreeService = $worktreeService;
    }

    public function handle(array $commandArgs, array $options): void
    {
        $agent = $options['agent'] ?? null;
        if (!is_string($agent)) {
            throw new \RuntimeException('Option --agent is required.');
        }
        $board = $this->loadBoard();
        $review = $this->loadReviewFile();
        $match = isset($commandArgs[0])
            ? $this->boardService->resolveTaskByReference($board, $commandArgs[0], BacklogCommandName::TASK_REVIEW_REQUEST->value)
            : $this->boardService->resolveSingleTaskForAgent($board, $agent);
        
        $entry = $match->getEntry();
        if ($entry->getAgent() !== $agent) {
            throw new \RuntimeException('task-review-request requires the task to be assigned to the provided agent.');
        }

        $taskWorktree = $this->worktreeService->prepareFeatureAgentWorktree($entry);
        $this->worktreeService->runReviewScript($taskWorktree, $entry->getBase());

        $entry->setStage(BacklogBoard::STAGE_IN_REVIEW);
        $review->clearReview($this->boardService->getTaskReviewKey($entry));
        $this->saveBoard($board, BacklogCommandName::TASK_REVIEW_REQUEST->value);
        $this->saveReviewFile($review, BacklogCommandName::TASK_REVIEW_REQUEST->value);

        $this->presenter->displaySuccess(sprintf(
            'Task %s moved to %s',
            $this->boardService->getTaskReviewKey($entry),
            $this->boardService->getStageLabel(BacklogBoard::STAGE_IN_REVIEW),
        ));
    }
}
