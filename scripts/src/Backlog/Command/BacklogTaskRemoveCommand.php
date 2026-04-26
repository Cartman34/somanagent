<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\BacklogBoard;
use SoManAgent\Script\Backlog\BacklogCommandName;

/**
 * Command for removing a task from the todo section.
 */
final class BacklogTaskRemoveCommand extends AbstractBacklogCommand
{
    public function __construct(BacklogCommandContext $context)
    {
        parent::__construct($context);
    }

    public function handle(array $commandArgs, array $options): void
    {
        $position = (int) ($commandArgs[0] ?? 0);
        if ($position <= 0) {
            throw new \RuntimeException('task-remove requires a positive task number.');
        }

        $board = $this->loadBoard();
        $entries = $board->getEntries(BacklogBoard::SECTION_TODO);
        $index = $position - 1;

        if (!isset($entries[$index])) {
            throw new \RuntimeException(sprintf('No queued task found at position %d.', $position));
        }

        $removed = $entries[$index];
        array_splice($entries, $index, 1);
        $board->setEntries(BacklogBoard::SECTION_TODO, array_values($entries));
        $this->saveBoard($board, BacklogCommandName::TASK_REMOVE->value);

        $this->console->ok(sprintf('Removed queued task %d', $position));
        $this->console->info($removed->getText());
    }
}
