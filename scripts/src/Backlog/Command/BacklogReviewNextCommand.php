<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Service\BacklogPresenter;
use RuntimeException;

/**
 * Command for displaying the next item to review.
 */
final class BacklogReviewNextCommand extends AbstractBacklogCommand
{
    public function __construct(
        BacklogPresenter $presenter,
        bool $dryRun,
        string $projectRoot,
        BacklogBoardService $boardService
    ) {
        parent::__construct($presenter, $dryRun, $projectRoot, $boardService);
    }

    public function handle(array $commandArgs, array $options): void
    {
        $board = $this->loadBoard();
        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $entry) {
            if ($this->boardService->getFeatureStage($entry) !== BacklogBoard::STAGE_IN_REVIEW) {
                continue;
            }

            $this->presenter->displayEntryStatus($entry);

            return;
        }

        throw new RuntimeException('No task or feature available in ' . $this->boardService->getStageLabel(BacklogBoard::STAGE_IN_REVIEW) . '.');
    }
}
