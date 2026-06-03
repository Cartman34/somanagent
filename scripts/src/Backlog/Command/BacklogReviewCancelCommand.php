<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Command;

use Sowapps\SoManAgent\Script\Backlog\Service\BacklogPresenter;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogBoardService;
use Sowapps\SoManAgent\Script\Backlog\Enum\BacklogCommandName;
use Sowapps\SoManAgent\Script\Backlog\Model\BacklogBoard;
use Sowapps\SoManAgent\Script\Backlog\Model\BoardEntry;
use Sowapps\SoManAgent\Script\Backlog\Command\AbstractBacklogCommand;
use RuntimeException;

/**
 * Releases a reviewing entry back to the review stage.
 *
 * The reviewer who claimed the entry (via review-next) must pass an explicit
 * `<entry-ref>`: no implicit resolution by agent so
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
        $agent = $this->requireCallerAgent();

        $rawReference = $commandArgs[0] ?? null;
        if (!is_string($rawReference) || trim($rawReference) === '') {
            throw new RuntimeException('review-cancel requires an explicit <entry-ref> reference.');
        }
        $reference = trim($rawReference);

        $isManager = $this->readCallerRole() === self::ROLE_MANAGER;

        $board = $this->loadBoard();
        $entry = $this->boardService
            ->resolveActiveEntryByReference($board, $reference, BacklogCommandName::REVIEW_CANCEL->value)
            ->getEntry();

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

        $entry->setStage(BacklogBoard::STAGE_PENDING_REVIEW);
        $entry->setReviewer(null);
        $this->saveBoard($board, BacklogCommandName::REVIEW_CANCEL->value);

        $this->presenter->displaySuccess(sprintf(
            '%s %s released back to %s',
            $this->boardService->checkIsTaskEntry($entry) ? 'Task' : 'Feature',
            $this->describeEntry($entry),
            $this->boardService->getStageLabel(BacklogBoard::STAGE_PENDING_REVIEW),
        ));
    }

    private function describeEntry(BoardEntry $entry): string
    {
        if ($this->boardService->checkIsTaskEntry($entry)) {
            return $this->boardService->getTaskReviewKey($entry);
        }

        return $entry->getFeature() ?? '-';
    }
}
