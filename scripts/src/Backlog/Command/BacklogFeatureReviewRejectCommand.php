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
use SoManAgent\Script\Console;

/**
 * Command for rejecting a feature review.
 */
final class BacklogFeatureReviewRejectCommand extends AbstractBacklogCommand
{
    private BacklogEntryResolver $entryResolver;

    private BacklogEntryService $entryService;

    private BacklogReviewBodyFormatter $reviewBodyFormatter;

    public function __construct(
        Console $console,
        bool $dryRun,
        string $projectRoot,
        BacklogEntryResolver $entryResolver,
        BacklogEntryService $entryService,
        BacklogReviewBodyFormatter $reviewBodyFormatter
    ) {
        parent::__construct($console, $dryRun, $projectRoot);
        $this->entryResolver = $entryResolver;
        $this->entryService = $entryService;
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
        $feature = $this->entryResolver->requireFeatureByReferenceArgument($board, $commandArgs, BacklogCommandName::FEATURE_REVIEW_REJECT->value);
        $match = $this->entryResolver->requireFeature($board, $feature);
        $entry = $match->getEntry();

        if ($this->entryService->featureStage($entry) !== BacklogBoard::STAGE_IN_REVIEW) {
            throw new \RuntimeException(sprintf(
                'Feature %s must be in %s to be rejected.',
                $feature,
                BacklogBoard::stageLabel(BacklogBoard::STAGE_IN_REVIEW),
            ));
        }

        $entry->setStage(BacklogBoard::STAGE_REJECTED);
        $review->setReview($feature, $this->reviewBodyFormatter->fromFile($bodyFile));
        $this->saveBoard($board, BacklogCommandName::FEATURE_REVIEW_REJECT->value);
        $this->saveReviewFile($review, BacklogCommandName::FEATURE_REVIEW_REJECT->value);

        $this->console->ok(sprintf(
            'Rejected feature %s, moved to %s',
            $feature,
            BacklogBoard::stageLabel(BacklogBoard::STAGE_REJECTED),
        ));
    }
}
