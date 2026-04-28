<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Model\BoardEntry;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Service\BacklogPresenter;

/**
 * Command for listing active features.
 */
final class BacklogFeatureListCommand extends AbstractBacklogCommand
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
        $printed = false;
        foreach ($this->boardService->getActiveStages() as $stage) {
            $entries = array_values(array_filter(
                $board->getEntries(BacklogBoard::SECTION_ACTIVE),
                fn(BoardEntry $entry): bool => $this->boardService->getFeatureStage($entry) === $stage
            ));
            if ($entries === []) {
                continue;
            }

            $printed = true;
            $this->presenter->displayStageHeader($stage);
            foreach ($entries as $entry) {
                $this->presenter->displayEntryLine($entry);
            }
        }

        if (!$printed) {
            $this->presenter->displayLine('No active feature.');
        }
    }
}
