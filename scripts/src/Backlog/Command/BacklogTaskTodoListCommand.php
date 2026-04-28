<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Service\BacklogPresenter;

/**
 * Command for listing tasks in the todo section.
 */
final class BacklogTaskTodoListCommand extends AbstractBacklogCommand
{
    public function __construct(BacklogPresenter $presenter, bool $dryRun, string $projectRoot, BacklogBoardService $boardService)
    {
        parent::__construct($presenter, $dryRun, $projectRoot, $boardService);
    }

    public function handle(array $commandArgs, array $options): void
    {
        $entries = $this->loadBoard()->getEntries(BacklogBoard::SECTION_TODO);
        if ($entries === []) {
            $this->presenter->displayLine('No queued task.');

            return;
        }

        foreach ($entries as $index => $entry) {
            $prefix = sprintf('%d. ', $index + 1);
            $this->presenter->displayLine($prefix . $entry->getText());
        }
    }
}
