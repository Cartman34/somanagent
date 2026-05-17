<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\Enum\BacklogCommandName;
use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Model\BoardEntry;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Service\BacklogPresenter;
use SoManAgent\Script\Backlog\Service\BacklogReviewBodyFormatter;
use SoManAgent\Script\Backlog\Service\BodyFilePathResolver;

/**
 * Reviewer command that replaces rejection notes on a rejected entry without changing its stage.
 *
 * Any reviewer may amend; no check is made against the original rejecting reviewer.
 * Short task references (bare task slug without the parent feature) are refused.
 */
final class BacklogReviewAmendCommand extends AbstractBacklogCommand
{
    private BacklogReviewBodyFormatter $reviewBodyFormatter;

    private BodyFilePathResolver $bodyFilePathResolver;

    /**
     * @param BacklogPresenter $presenter
     * @param bool $dryRun
     * @param string $projectRoot
     * @param BacklogBoardService $boardService
     * @param BacklogReviewBodyFormatter $reviewBodyFormatter
     * @param BodyFilePathResolver $bodyFilePathResolver
     */
    public function __construct(
        BacklogPresenter $presenter,
        bool $dryRun,
        string $projectRoot,
        BacklogBoardService $boardService,
        BacklogReviewBodyFormatter $reviewBodyFormatter,
        BodyFilePathResolver $bodyFilePathResolver
    ) {
        parent::__construct($presenter, $dryRun, $projectRoot, $boardService);
        $this->reviewBodyFormatter = $reviewBodyFormatter;
        $this->bodyFilePathResolver = $bodyFilePathResolver;
    }

    /**
     * @param list<string> $commandArgs
     * @param array<string, bool|string|array<bool|string>> $options
     */
    public function handle(array $commandArgs, array $options): void
    {
        $role = strtolower(trim((string) getenv('SOMANAGER_ROLE')));
        if ($role !== 'reviewer') {
            throw new \RuntimeException('review-amend is restricted to the reviewer role (SOMANAGER_ROLE=reviewer).');
        }

        $this->requireCallerAgent();

        $reference = trim($commandArgs[0] ?? '');
        if ($reference === '') {
            throw new \RuntimeException('review-amend requires <entry-ref>.');
        }

        $bodyFile = $options['body-file'] ?? null;
        if (!is_string($bodyFile) || $bodyFile === '') {
            throw new \RuntimeException('review-amend requires --body-file=<path>.');
        }

        $board = $this->loadBoard();
        $review = $this->loadReviewFile();

        if (str_contains($reference, '/')) {
            $match = $this->boardService->resolveTaskByReference($board, $reference, BacklogCommandName::REVIEW_AMEND->value);
            $entry = $match->getEntry();
            $this->assertStageIsRejected($entry);

            $reviewKey = $this->boardService->getTaskReviewKey($entry);
            $review->setReview($reviewKey, $this->reviewBodyFormatter->fromFile($this->bodyFilePathResolver->resolveForEntry($bodyFile, $reference)));
            $this->saveReviewFile($review, BacklogCommandName::REVIEW_AMEND->value);

            $this->presenter->displaySuccess(sprintf('Amended review notes for task %s', $reviewKey));

            return;
        }

        $slug = $this->boardService->normalizeFeatureSlug($reference);
        if ($this->boardService->findParentFeatureEntry($board, $slug) === null) {
            $taskMatches = $this->boardService->findTaskEntriesByTaskSlug($board, $slug);
            if ($taskMatches !== []) {
                throw new \RuntimeException(sprintf(
                    'review-amend refuses short task reference `%s`; use `<entry-ref>` instead.',
                    $slug,
                ));
            }
        }

        $match = $this->boardService->resolveFeature($board, $slug);
        $entry = $match->getEntry();
        $this->assertStageIsRejected($entry);

        $review->setReview($slug, $this->reviewBodyFormatter->fromFile($this->bodyFilePathResolver->resolveForEntry($bodyFile, $slug)));
        $this->saveReviewFile($review, BacklogCommandName::REVIEW_AMEND->value);

        $this->presenter->displaySuccess(sprintf('Amended review notes for feature %s', $slug));
    }

    /**
     * Refuses unless the entry is in rejected stage.
     */
    private function assertStageIsRejected(BoardEntry $entry): void
    {
        $stage = $this->boardService->getFeatureStage($entry);
        if ($stage === BacklogBoard::STAGE_REJECTED) {
            return;
        }

        $label = $this->boardService->checkIsTaskEntry($entry)
            ? sprintf('Task %s', $this->boardService->getTaskReviewKey($entry))
            : sprintf('Feature %s', $entry->getFeature() ?? '');

        throw new \RuntimeException(sprintf(
            '%s must be in %s to be amended.',
            $label,
            $this->boardService->getStageLabel(BacklogBoard::STAGE_REJECTED),
        ));
    }
}
