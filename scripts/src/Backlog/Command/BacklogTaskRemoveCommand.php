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
/**
 * Removes a queued task from the todo section using its stable reference.
 *
 * Queued tasks are identified by their `<entry-ref>` as shown by `list --stage=todo`.
 * Display numbers are advisory only and never accepted as mutation identity.
 */
final class BacklogTaskRemoveCommand extends AbstractBacklogCommand
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
     * Removes the queued entry whose stable reference matches the positional argument.
     *
     * @param list<string> $commandArgs
     * @param array<string, bool|string|array<bool|string>> $options
     */
    public function handle(array $commandArgs, array $options): void
    {
        $reference = isset($commandArgs[0]) ? (string) $commandArgs[0] : '';
        $command = BacklogCommandName::ENTRY_REMOVE->value;
        $board = $this->loadBoard();

        [$index, $removed] = $this->boardService->resolveQueuedEntryByReference($board, $reference, $command);
        $entries = $board->getEntries(BacklogBoard::SECTION_TODO);
        array_splice($entries, $index, 1);
        $board->setEntries(BacklogBoard::SECTION_TODO, array_values($entries));
        $this->saveBoard($board, $command);

        $this->presenter->displaySuccess(sprintf(
            'Removed queued task %s',
            $this->boardService->computeQueuedEntryReference($removed),
        ));
        $this->presenter->displayInfo($removed->getText());
    }
}
