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
use RuntimeException;

/**
 * Command for reworking a rejected task.
 */
final class BacklogTaskReworkCommand extends AbstractBacklogCommand
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
            throw new RuntimeException('Option --agent is required.');
        }
        $board = $this->loadBoard();
        $match = isset($commandArgs[0])
            ? $this->boardService->resolveTaskByReference($board, $commandArgs[0], BacklogCommandName::TASK_REWORK->value)
            : $this->boardService->resolveSingleTaskForAgent($board, $agent);
        $entry = $match->getEntry();

        if ($entry->getAgent() !== $agent) {
            throw new RuntimeException('task-rework requires the task to be assigned to the provided agent.');
        }

        if ($this->boardService->getFeatureStage($entry) !== BacklogBoard::STAGE_REJECTED) {
            throw new RuntimeException(sprintf(
                'Task %s is not in the rejected stage.',
                $this->boardService->getTaskReviewKey($entry),
            ));
        }

        $entry->setStage(BacklogBoard::STAGE_IN_PROGRESS);
        $this->saveBoard($board, BacklogCommandName::TASK_REWORK->value);

        $worktree = $this->worktreeService->prepareAgentWorktree($agent);
        $this->worktreeService->checkoutBranchInWorktree($worktree, $entry->getBranch() ?? '', false);

        $this->presenter->displaySuccess(sprintf(
            'Task %s moved back to %s',
            $this->boardService->getTaskReviewKey($entry),
            $this->boardService->getStageLabel(BacklogBoard::STAGE_IN_PROGRESS),
        ));
    }
}
