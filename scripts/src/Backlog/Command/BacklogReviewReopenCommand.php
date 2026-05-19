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
use RuntimeException;

/**
 * Reopens an approved entry for a new review cycle.
 *
 * Only manager and reviewer roles may call this command.
 * An explicit `<entry-ref>` is always required — no implicit resolution.
 * Existing review notes for the entry are cleared on success.
 *
 * Behaviour by role:
 *   - manager: `approved → review`, `meta.reviewer` cleared.
 *   - reviewer: `approved → reviewing`, `meta.reviewer` set to the calling reviewer code.
 *     Non-exclusive: if the originally assigned reviewer is unavailable, another reviewer
 *     may use `review-reopen` to claim the entry directly.
 */
final class BacklogReviewReopenCommand extends AbstractBacklogCommand
{
    private const ROLE_MANAGER = 'manager';
    private const ROLE_REVIEWER = 'reviewer';

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
     * @param array<string, bool|string|array<bool|string>> $options
     */
    public function handle(array $commandArgs, array $options): void
    {
        $agent = $this->requireCallerAgent();
        $role = strtolower(trim((string) getenv('SOMANAGER_ROLE')));

        if (!in_array($role, [self::ROLE_MANAGER, self::ROLE_REVIEWER], true)) {
            throw new RuntimeException(
                'review-reopen requires SOMANAGER_ROLE=manager or SOMANAGER_ROLE=reviewer.'
            );
        }

        $rawReference = $commandArgs[0] ?? null;
        if (!is_string($rawReference) || trim($rawReference) === '') {
            throw new RuntimeException('review-reopen requires an explicit <entry-ref>.');
        }
        $reference = trim($rawReference);

        $board = $this->loadBoard();
        $entry = $this->resolveByReference($board, $reference);

        $stage = $this->boardService->getFeatureStage($entry);
        if ($stage !== BacklogBoard::STAGE_APPROVED) {
            throw new RuntimeException(sprintf(
                'Entry %s has stage %s, but review-reopen only accepts %s.',
                $this->describeEntry($entry),
                $this->boardService->getStageLabel($stage),
                $this->boardService->getStageLabel(BacklogBoard::STAGE_APPROVED),
            ));
        }

        $reviewKey = $this->boardService->checkIsTaskEntry($entry)
            ? $this->boardService->getTaskReviewKey($entry)
            : ($entry->getFeature() ?? '-');

        $review = $this->loadReviewFile();
        $review->clearReview($reviewKey);

        $isManager = $role === self::ROLE_MANAGER;
        $targetStage = $isManager ? BacklogBoard::STAGE_PENDING_REVIEW : BacklogBoard::STAGE_REVIEWING;

        $entry->setStage($targetStage);
        $entry->setReviewer($isManager ? null : $agent);

        $this->saveBoard($board, BacklogCommandName::REVIEW_REOPEN->value);
        $this->saveReviewFile($review, BacklogCommandName::REVIEW_REOPEN->value);

        $this->presenter->displaySuccess(sprintf(
            '%s %s moved from %s to %s',
            $this->boardService->checkIsTaskEntry($entry) ? 'Task' : 'Feature',
            $reviewKey,
            $this->boardService->getStageLabel(BacklogBoard::STAGE_APPROVED),
            $this->boardService->getStageLabel($targetStage),
        ));
    }

    /**
     * Resolves the target entry by its stable reference.
     *
     * A slash in the reference identifies a task (`<feature>/<task>`); otherwise the
     * reference is treated as a feature slug, with an ambiguity guard when the same
     * slug matches both a feature and a task.
     */
    private function resolveByReference(BacklogBoard $board, string $reference): BoardEntry
    {
        if (str_contains($reference, '/')) {
            return $this->boardService->resolveTaskByReference($board, $reference, BacklogCommandName::REVIEW_REOPEN->value)->getEntry();
        }

        $slug = $this->boardService->normalizeFeatureSlug($reference);
        $featureMatch = $this->boardService->findParentFeatureEntry($board, $slug);
        $taskMatches = $this->boardService->findTaskEntriesByTaskSlug($board, $slug);

        if ($featureMatch !== null && $taskMatches !== []) {
            throw new RuntimeException(sprintf(
                'Ambiguous reference %s: matches both a feature and a task. Use a full <entry-ref> to disambiguate.',
                $reference,
            ));
        }

        if ($featureMatch !== null) {
            return $featureMatch->getEntry();
        }

        if ($taskMatches !== []) {
            if (count($taskMatches) > 1) {
                throw new RuntimeException(sprintf(
                    'review-reopen requires a full <entry-ref> because task slug %s is not unique.',
                    $slug,
                ));
            }

            return $taskMatches[0]->getEntry();
        }

        throw new RuntimeException(sprintf('No active entry found for reference: %s', $reference));
    }

    /**
     * Returns a human-readable identifier for the entry.
     */
    private function describeEntry(BoardEntry $entry): string
    {
        if ($this->boardService->checkIsTaskEntry($entry)) {
            return $this->boardService->getTaskReviewKey($entry);
        }

        return $entry->getFeature() ?? '-';
    }
}
