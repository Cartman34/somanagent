<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\BacklogBoard;
use SoManAgent\Script\Backlog\BacklogCommandFactory;
use SoManAgent\Script\Backlog\BacklogCommandName;
use SoManAgent\Script\Backlog\BacklogEntryResolver;
use SoManAgent\Script\Backlog\BacklogEntryService;
use SoManAgent\Script\Backlog\BacklogWorktreeManager;

/**
 * Command for checking a task review.
 */
final class BacklogTaskReviewCheckCommand extends AbstractBacklogCommand
{
    private BacklogEntryResolver $entryResolver;

    private BacklogEntryService $entryService;

    private BacklogWorktreeManager $worktreeManager;

    private BacklogCommandFactory $commandFactory;

    public function __construct(BacklogCommandContext $context)
    {
        parent::__construct($context);
        $this->entryResolver = $context->getEntryResolver();
        $this->entryService = $context->getEntryService();
        $this->worktreeManager = $context->getWorktreeManager();
        $this->commandFactory = $context->getCommandFactory();
    }

    public function handle(array $commandArgs, array $options): void
    {
        $board = $this->loadBoard();
        $match = $this->entryResolver->requireTaskByReferenceArgument($board, $commandArgs, BacklogCommandName::TASK_REVIEW_CHECK->value);
        $entry = $match->getEntry();

        if ($this->entryService->featureStage($entry) !== BacklogBoard::STAGE_IN_REVIEW) {
            throw new \RuntimeException(sprintf(
                'Task %s must be in %s to be checked.',
                $this->entryService->taskReviewKey($entry),
                BacklogBoard::stageLabel(BacklogBoard::STAGE_IN_REVIEW),
            ));
        }

        $reviewWorktree = $this->worktreeManager->prepareFeatureAgentWorktree($entry);

        try {
            $this->worktreeManager->runReviewScript($reviewWorktree, $entry->getBase());
        } catch (\RuntimeException $exception) {
            $message = 'Mechanical review `php scripts/review.php` failed. Fix mechanical issues before submitting the task again.';
            
            // Delegate to reject command
            $tempBodyFile = tempnam(sys_get_temp_dir(), 'somanagent-review-');
            file_put_contents($tempBodyFile, $message);
            
            $this->commandFactory->createHandler(BacklogCommandName::TASK_REVIEW_REJECT->value)->handle(
                [$this->entryService->taskReviewKey($entry)],
                ['body-file' => $tempBodyFile]
            );
            
            unlink($tempBodyFile);
            
            throw $exception;
        }

        $this->console->ok(sprintf('Mechanical review passed for task %s', $this->entryService->taskReviewKey($entry)));
    }
}
