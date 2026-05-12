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
 * Lists queued tasks in priority order with their stable mutation reference.
 *
 * Each line shows the display index, the stable feature (or feature/task) slug
 * between brackets, and the original task text. Display numbers are advisory
 * only; mutations such as task-remove always require the stable reference.
 */
final class BacklogTodoListCommand extends AbstractBacklogCommand
{
    /**
     * @param BacklogPresenter $presenter
     * @param bool $dryRun
     * @param string $projectRoot
     * @param BacklogBoardService $boardService
     */
    public function __construct(BacklogPresenter $presenter, bool $dryRun, string $projectRoot, BacklogBoardService $boardService)
    {
        parent::__construct($presenter, $dryRun, $projectRoot, $boardService);
    }

    /**
     * List queued todo tasks in priority order.
     *
     * @param list<string> $commandArgs
     * @param array<string, bool|string|array<bool|string>> $options
     */
    public function handle(array $commandArgs, array $options): void
    {
        $entries = $this->loadBoard()->getEntries(BacklogBoard::SECTION_TODO);
        if ($entries === []) {
            $this->presenter->displayLine('No queued task.');

            return;
        }

        foreach ($entries as $index => $entry) {
            $reference = $this->boardService->computeQueuedEntryReference($entry);
            $this->presenter->displayLine(sprintf('%d. [%s] %s', $index + 1, $reference, $entry->getText()));
        }
    }
}
