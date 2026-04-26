<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\BacklogBoard;
use SoManAgent\Script\Backlog\BacklogEntryService;
use SoManAgent\Script\Backlog\BoardEntry;
use SoManAgent\Script\Backlog\BacklogPresenter;

/**
 * Command for listing active features.
 */
final class BacklogFeatureListCommand extends AbstractBacklogCommand
{
    private BacklogEntryService $entryService;

    public function __construct(
        BacklogPresenter $presenter,
        bool $dryRun,
        string $projectRoot,
        BacklogEntryService $entryService
    ) {
        parent::__construct($presenter, $dryRun, $projectRoot);
        $this->entryService = $entryService;
    }

    public function handle(array $commandArgs, array $options): void
    {
        $board = $this->loadBoard();
        $printed = false;
        foreach (BacklogBoard::activeStages() as $stage) {
            $entries = array_values(array_filter(
                $board->getEntries(BacklogBoard::SECTION_ACTIVE),
                fn(BoardEntry $entry): bool => $this->entryService->featureStage($entry) === $stage
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
