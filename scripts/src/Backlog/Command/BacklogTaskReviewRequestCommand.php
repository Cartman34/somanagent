<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\BacklogBoard;
use SoManAgent\Script\Backlog\BacklogCommandName;
use SoManAgent\Script\Backlog\BacklogEntryResolver;
use SoManAgent\Script\Backlog\BacklogEntryService;
use SoManAgent\Script\Backlog\BacklogWorktreeManager;
use SoManAgent\Script\Backlog\BacklogPresenter;

/**
 * Command for requesting a review for a task.
 */
final class BacklogTaskReviewRequestCommand extends AbstractBacklogCommand
{
    private BacklogEntryResolver $entryResolver;

    private BacklogEntryService $entryService;

    private BacklogWorktreeManager $worktreeManager;

    public function __construct(
        BacklogPresenter $presenter,
        bool $dryRun,
        string $projectRoot,
        BacklogEntryResolver $entryResolver,
        BacklogEntryService $entryService,
        BacklogWorktreeManager $worktreeManager
    ) {
        parent::__construct($presenter, $dryRun, $projectRoot);
        $this->entryResolver = $entryResolver;
        $this->entryService = $entryService;
        $this->worktreeManager = $worktreeManager;
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
            ? $this->entryResolver->requireTaskByReference($board, $commandArgs[0], BacklogCommandName::TASK_REVIEW_REQUEST->value)
            : $this->entryResolver->requireSingleTaskForAgent($board, $agent);
        
        $entry = $match->getEntry();
        $this->entryService->assertTaskEntry($entry, BacklogCommandName::TASK_REVIEW_REQUEST->value);
        if ($entry->getAgent() !== $agent) {
            throw new \RuntimeException('task-review-request requires the task to be assigned to the provided agent.');
        }

        $taskWorktree = $this->worktreeManager->prepareFeatureAgentWorktree($entry);
        $this->worktreeManager->runReviewScript($taskWorktree, $entry->getBase());

        $entry->setStage(BacklogBoard::STAGE_IN_REVIEW);
        $review->clearReview($this->entryService->taskReviewKey($entry));
        $this->saveBoard($board, BacklogCommandName::TASK_REVIEW_REQUEST->value);
        $this->saveReviewFile($review, BacklogCommandName::TASK_REVIEW_REQUEST->value);

        $this->presenter->displaySuccess(sprintf(
            'Task %s moved to %s',
            $this->entryService->taskReviewKey($entry),
            BacklogBoard::stageLabel(BacklogBoard::STAGE_IN_REVIEW),
        ));
    }
}
