<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\BacklogBoard;
use SoManAgent\Script\Backlog\BacklogCommandName;
use SoManAgent\Script\Backlog\BacklogEntryService;

/**
 * Command for creating a new task in the todo section.
 */
final class BacklogTaskCreateCommand extends AbstractBacklogCommand
{
    private const POSITION_START = 'start';
    private const POSITION_INDEX = 'index';
    private const POSITION_END = 'end';

    private BacklogEntryService $entryService;

    public function __construct(BacklogCommandContext $context)
    {
        parent::__construct($context);
        $this->entryService = $context->getEntryService();
    }

    public function handle(array $commandArgs, array $options): void
    {
        $text = trim(implode(' ', $commandArgs));
        if ($text === '') {
            throw new \RuntimeException('This command requires a task description.');
        }

        $board = $this->loadBoard();
        $entries = $board->getEntries(BacklogBoard::SECTION_TODO);
        $position = $this->resolveTaskCreatePosition($options, count($entries));
        array_splice($entries, $position, 0, [$this->entryService->createTaskEntryFromInput($text)]);
        $board->setEntries(BacklogBoard::SECTION_TODO, $entries);
        $this->saveBoard($board, BacklogCommandName::TASK_CREATE->value);

        $this->console->ok(sprintf('Added task to the todo section at position %d', $position + 1));
    }

    /**
     * Resolves the 0-based insertion index for task-create from options.
     *
     * @param array<string, string|bool> $options
     */
    private function resolveTaskCreatePosition(array $options, int $entryCount): int
    {
        $position = (string) ($options['position'] ?? self::POSITION_END);
        if (!in_array($position, [
            self::POSITION_START,
            self::POSITION_INDEX,
            self::POSITION_END,
        ], true)) {
            throw new \RuntimeException('task-create --position must be start, index, or end.');
        }

        if ($position === self::POSITION_START) {
            return 0;
        }

        if ($position === self::POSITION_END) {
            return $entryCount;
        }

        $rawIndex = (int) ($options['index'] ?? 0);
        if ($rawIndex <= 0) {
            throw new \RuntimeException('task-create with --position=index requires --index=<positive-number>.');
        }

        return min($entryCount, $rawIndex - 1);
    }
}
