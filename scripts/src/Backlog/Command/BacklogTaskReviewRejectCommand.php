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
use SoManAgent\Script\Backlog\BacklogReviewBodyFormatter;

/**
 * Command for rejecting a task review.
 */
final class BacklogTaskReviewRejectCommand extends AbstractBacklogCommand
{
    private BacklogEntryResolver $entryResolver;

    private BacklogEntryService $entryService;

    private BacklogReviewBodyFormatter $reviewBodyFormatter;

    public function __construct(BacklogCommandContext $context)
    {
        parent::__construct($context);
        $this->entryResolver = $context->getEntryResolver();
        $this->entryService = $context->getEntryService();
        $this->reviewBodyFormatter = $context->getReviewBodyFormatter();
    }

    public function handle(array $commandArgs, array $options): void
    {
        $bodyFile = $options['body-file'] ?? null;
        if (!is_string($bodyFile)) {
            throw new \RuntimeException('Option --body-file is required.');
        }

        $board = $this->loadBoard();
        $review = $this->loadReviewFile();
        $match = $this->entryResolver->requireTaskByReferenceArgument($board, $commandArgs, BacklogCommandName::TASK_REVIEW_REJECT->value);
        $entry = $match->getEntry();

        if ($this->entryService->featureStage($entry) !== BacklogBoard::STAGE_IN_REVIEW) {
            throw new \RuntimeException(sprintf(
                'Task %s must be in %s to be rejected.',
                $this->entryService->taskReviewKey($entry),
                BacklogBoard::stageLabel(BacklogBoard::STAGE_IN_REVIEW),
            ));
        }

        $entry->setStage(BacklogBoard::STAGE_REJECTED);
        $review->setReview($this->entryService->taskReviewKey($entry), $this->reviewBodyFormatter->fromFile($bodyFile));
        $this->saveBoard($board, BacklogCommandName::TASK_REVIEW_REJECT->value);
        $this->saveReviewFile($review, BacklogCommandName::TASK_REVIEW_REJECT->value);

        $this->console->ok(sprintf(
            'Rejected task %s, moved to %s',
            $this->entryService->taskReviewKey($entry),
            BacklogBoard::stageLabel(BacklogBoard::STAGE_REJECTED),
        ));
    }
}
