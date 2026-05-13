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
 * Command for claiming the next item to review and transitioning it to the reviewing stage.
 */
final class BacklogReviewNextCommand extends AbstractBacklogCommand
{
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
        $explicitReference = $commandArgs[0] ?? null;

        $board = $this->loadBoard();

        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $entry) {
            if ($this->boardService->getFeatureStage($entry) !== BacklogBoard::STAGE_REVIEWING) {
                continue;
            }
            if ($entry->getReviewer() === $agent) {
                throw new RuntimeException(sprintf(
                    'Reviewer %s already has an entry in %s. Run review-cancel to release it first.',
                    $agent,
                    $this->boardService->getStageLabel(BacklogBoard::STAGE_REVIEWING),
                ));
            }
        }

        $picked = $explicitReference !== null
            ? $this->resolveExplicitTarget($board, $explicitReference)
            : $this->resolveNextReviewEntry($board);

        $picked->setStage(BacklogBoard::STAGE_REVIEWING);
        $picked->setReviewer($agent);
        $this->saveBoard($board, BacklogCommandName::REVIEW_NEXT->value);

        $this->presenter->displayEntryStatus($picked);
    }

    /**
     * Picks the first active entry in the review stage.
     */
    private function resolveNextReviewEntry(BacklogBoard $board): BoardEntry
    {
        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $entry) {
            if ($this->boardService->getFeatureStage($entry) === BacklogBoard::STAGE_IN_REVIEW) {
                return $entry;
            }
        }

        throw new RuntimeException(
            'No task or feature available in ' . $this->boardService->getStageLabel(BacklogBoard::STAGE_IN_REVIEW) . '.'
        );
    }

    /**
     * Locates the active entry matching the given reference and verifies it is still in review.
     *
     * The reference is an `<entry-ref>` as exposed by `review-list`. The lookup
     * uses the recorded `meta.feature` and `meta.task` of active entries.
     */
    private function resolveExplicitTarget(BacklogBoard $board, string $reference): BoardEntry
    {
        $trimmed = trim($reference);
        if ($trimmed === '') {
            throw new RuntimeException('review-next: target reference cannot be empty.');
        }

        $slashCount = substr_count($trimmed, '/');
        if ($slashCount > 1) {
            throw new RuntimeException(sprintf(
                'Invalid target reference "%s": expected <entry-ref>.',
                $reference,
            ));
        }

        $expectedFeature = null;
        $expectedTask = null;
        if ($slashCount === 1) {
            [$expectedFeature, $expectedTask] = explode('/', $trimmed, 2);
        } else {
            $expectedFeature = $trimmed;
        }

        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $entry) {
            if ($entry->getFeature() !== $expectedFeature) {
                continue;
            }
            $entryTask = $entry->getTask();
            if ($expectedTask === null) {
                if ($entryTask !== null) {
                    continue;
                }
            } elseif ($entryTask !== $expectedTask) {
                continue;
            }

            $stage = $this->boardService->getFeatureStage($entry);
            if ($stage === BacklogBoard::STAGE_REVIEWING) {
                throw new RuntimeException(sprintf(
                    'Entry "%s" is already in %s by reviewer %s. Wait for them or run review-cancel.',
                    $reference,
                    $this->boardService->getStageLabel(BacklogBoard::STAGE_REVIEWING),
                    $entry->getReviewer() ?? '-',
                ));
            }
            if ($stage !== BacklogBoard::STAGE_IN_REVIEW) {
                throw new RuntimeException(sprintf(
                    'Entry "%s" is not in %s (current stage: %s). Only entries waiting in review can be claimed.',
                    $reference,
                    $this->boardService->getStageLabel(BacklogBoard::STAGE_IN_REVIEW),
                    $this->boardService->getStageLabel($stage),
                ));
            }

            return $entry;
        }

        throw new RuntimeException(sprintf(
            'No active entry matches reference "%s". Run `review-list` to see available references.',
            $reference,
        ));
    }
}
