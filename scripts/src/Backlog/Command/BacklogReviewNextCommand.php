<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\BacklogBoard;
use SoManAgent\Script\Backlog\BacklogEntryService;
use SoManAgent\Script\Backlog\BacklogPresenter;

/**
 * Command for displaying the next item to review.
 */
final class BacklogReviewNextCommand extends AbstractBacklogCommand
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
        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $entry) {
            if ($this->entryService->featureStage($entry) !== BacklogBoard::STAGE_IN_REVIEW) {
                continue;
            }

            $this->presenter->displayEntryStatus($entry);

            return;
        }

        throw new \RuntimeException('No task or feature available in ' . BacklogBoard::stageLabel(BacklogBoard::STAGE_IN_REVIEW) . '.');
    }
}
