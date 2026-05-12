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
 * Releases a reviewing entry back to the review stage.
 *
 * The reviewer who claimed the entry (via review-next) must pass an explicit
 * `<feature>` or `<feature/task>` reference: no implicit resolution by agent so
 * the mutation never silently retargets a different entry. A manager
 * (SOMANAGER_ROLE=manager) may force-cancel any stuck review with the same
 * explicit reference contract.
 */
final class BacklogReviewCancelCommand extends AbstractBacklogCommand
{
    private const ROLE_MANAGER = 'manager';

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
        $agent = $options['agent'] ?? null;
        if (!is_string($agent) || $agent === '') {
            throw new RuntimeException('review-cancel requires --agent=<reviewer>.');
        }

        $reference = $commandArgs[0] ?? null;
        if (!is_string($reference) || trim($reference) === '') {
            throw new RuntimeException('review-cancel requires an explicit <feature> or <feature/task> reference.');
        }

        $isManager = strtolower(trim((string) getenv('SOMANAGER_ROLE'))) === self::ROLE_MANAGER;

        $board = $this->loadBoard();
        $entry = $this->resolveByReference($board, $reference);

        $stage = $this->boardService->getFeatureStage($entry);
        if ($stage !== BacklogBoard::STAGE_REVIEWING) {
            throw new RuntimeException(sprintf(
                'Entry %s has stage %s, but review-cancel only accepts %s.',
                $this->describeEntry($entry),
                $this->boardService->getStageLabel($stage),
                $this->boardService->getStageLabel(BacklogBoard::STAGE_REVIEWING),
            ));
        }

        $claimedBy = $entry->getReviewer();
        if (!$isManager && $claimedBy !== $agent) {
            throw new RuntimeException(sprintf(
                'Entry %s is claimed by reviewer %s. Only that reviewer or a manager can cancel it.',
                $this->describeEntry($entry),
                $claimedBy ?? '-',
            ));
        }

        $entry->setStage(BacklogBoard::STAGE_IN_REVIEW);
        $entry->setReviewer(null);
        $this->saveBoard($board, BacklogCommandName::REVIEW_CANCEL->value);

        $this->presenter->displaySuccess(sprintf(
            '%s %s released back to %s',
            $this->boardService->checkIsTaskEntry($entry) ? 'Task' : 'Feature',
            $this->describeEntry($entry),
            $this->boardService->getStageLabel(BacklogBoard::STAGE_IN_REVIEW),
        ));
    }

    private function resolveByReference(BacklogBoard $board, string $reference): BoardEntry
    {
        if (str_contains($reference, '/')) {
            return $this->boardService->resolveTaskByReference($board, $reference, BacklogCommandName::REVIEW_CANCEL->value)->getEntry();
        }

        $slug = $this->boardService->normalizeFeatureSlug($reference);
        $featureMatch = $this->boardService->findParentFeatureEntry($board, $slug);
        $taskMatches = $this->boardService->findTaskEntriesByTaskSlug($board, $slug);

        if ($featureMatch !== null && $taskMatches !== []) {
            throw new RuntimeException(sprintf(
                'Ambiguous reference %s: matches both a feature and a task. Use <feature/task> to disambiguate.',
                $reference,
            ));
        }

        if ($featureMatch !== null) {
            return $featureMatch->getEntry();
        }

        if ($taskMatches !== []) {
            if (count($taskMatches) > 1) {
                throw new RuntimeException(sprintf(
                    'review-cancel requires <feature/task> because task slug %s is not unique.',
                    $slug,
                ));
            }

            return $taskMatches[0]->getEntry();
        }

        throw new RuntimeException(sprintf('No active entry found for reference: %s', $reference));
    }

    private function describeEntry(BoardEntry $entry): string
    {
        if ($this->boardService->checkIsTaskEntry($entry)) {
            return $this->boardService->getTaskReviewKey($entry);
        }

        return $entry->getFeature() ?? '-';
    }
}
