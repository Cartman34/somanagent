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
use SoManAgent\Script\Console;

/**
 * Command for approving a task review.
 */
final class BacklogTaskReviewApproveCommand extends AbstractBacklogCommand
{
    private BacklogEntryResolver $entryResolver;

    private BacklogEntryService $entryService;

    public function __construct(
        Console $console,
        bool $dryRun,
        string $projectRoot,
        BacklogEntryResolver $entryResolver,
        BacklogEntryService $entryService
    ) {
        parent::__construct($console, $dryRun, $projectRoot);
        $this->entryResolver = $entryResolver;
        $this->entryService = $entryService;
    }

    public function handle(array $commandArgs, array $options): void
    {
        $board = $this->loadBoard();
        $review = $this->loadReviewFile();
        $match = $this->entryResolver->requireTaskByReferenceArgument($board, $commandArgs, BacklogCommandName::TASK_REVIEW_APPROVE->value);
        $entry = $match->getEntry();

        if ($this->entryService->featureStage($entry) !== BacklogBoard::STAGE_IN_REVIEW) {
            throw new \RuntimeException(sprintf(
                'Task %s must be in %s to be approved.',
                $this->entryService->taskReviewKey($entry),
                BacklogBoard::stageLabel(BacklogBoard::STAGE_IN_REVIEW),
            ));
        }

        $entry->setStage(BacklogBoard::STAGE_APPROVED);
        $review->clearReview($this->entryService->taskReviewKey($entry));
        $this->saveBoard($board, BacklogCommandName::TASK_REVIEW_APPROVE->value);
        $this->saveReviewFile($review, BacklogCommandName::TASK_REVIEW_APPROVE->value);

        $this->console->ok(sprintf('Approved task %s', $this->entryService->taskReviewKey($entry)));
    }
}
