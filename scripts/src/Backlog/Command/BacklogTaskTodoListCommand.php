<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\BacklogBoard;

/**
 * Command for listing tasks in the todo section.
 */
final class BacklogTaskTodoListCommand extends AbstractBacklogCommand
{
    public function __construct(BacklogCommandContext $context)
    {
        parent::__construct($context);
    }

    public function handle(array $commandArgs, array $options): void
    {
        $entries = $this->loadBoard()->getEntries(BacklogBoard::SECTION_TODO);
        if ($entries === []) {
            $this->console->line('No queued task.');

            return;
        }

        foreach ($entries as $index => $entry) {
            $prefix = sprintf('%d. ', $index + 1);
            $this->console->line($prefix . $entry->getText());
        }
    }
}
