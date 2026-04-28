<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\BacklogCommandFactory;
use SoManAgent\Script\Backlog\Enum\BacklogCommandName;
use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Service\BacklogPresenter;
use SoManAgent\Script\Backlog\Service\BacklogWorktreeService;
use SoManAgent\Script\Client\FilesystemClientInterface;

/**
 * Command for checking a task review.
 */
final class BacklogTaskReviewCheckCommand extends AbstractBacklogCommand
{
    private BacklogWorktreeService $worktreeService;

    private BacklogCommandFactory $commandFactory;

    private FilesystemClientInterface $fs;

    public function __construct(
        BacklogPresenter $presenter,
        bool $dryRun,
        string $projectRoot,
        BacklogBoardService $boardService,
        BacklogWorktreeService $worktreeService,
        BacklogCommandFactory $commandFactory,
        FilesystemClientInterface $fs
    ) {
        parent::__construct($presenter, $dryRun, $projectRoot, $boardService);
        $this->worktreeService = $worktreeService;
        $this->commandFactory = $commandFactory;
        $this->fs = $fs;
    }

    public function handle(array $commandArgs, array $options): void
    {
        $board = $this->loadBoard();
        $match = $this->boardService->resolveTaskByReference($board, $commandArgs[0] ?? '', BacklogCommandName::TASK_REVIEW_CHECK->value);
        $entry = $match->getEntry();

        if ($this->boardService->getFeatureStage($entry) !== BacklogBoard::STAGE_IN_REVIEW) {
            throw new \RuntimeException(sprintf(
                'Task %s must be in %s to be checked.',
                $this->boardService->getTaskReviewKey($entry),
                $this->boardService->getStageLabel(BacklogBoard::STAGE_IN_REVIEW),
            ));
        }

        $reviewWorktree = $this->worktreeService->prepareFeatureAgentWorktree($entry);

        try {
            $this->worktreeService->runReviewScript($reviewWorktree, $entry->getBase());
        } catch (\RuntimeException $exception) {
            $message = 'Mechanical review `php scripts/review.php` failed. Fix mechanical issues before submitting the task again.';
            
            // Delegate to reject command
            $tempBodyFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'somanagent-review-' . bin2hex(random_bytes(8));
            $this->fs->writeFilePath($tempBodyFile, $message);
            
            $this->commandFactory->createHandler(BacklogCommandName::TASK_REVIEW_REJECT->value)->handle(
                [$this->boardService->getTaskReviewKey($entry)],
                ['body-file' => $tempBodyFile]
            );
            
            $this->fs->removePath($tempBodyFile);
            
            throw $exception;
        }

        $this->presenter->displaySuccess(sprintf('Mechanical review passed for task %s', $this->boardService->getTaskReviewKey($entry)));
    }
}
