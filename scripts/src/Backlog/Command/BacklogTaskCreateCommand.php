<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\Enum\BacklogCommandName;
use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Model\BoardEntry;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Service\BacklogPresenter;

/**
 * Inserts a new task into the todo section.
 *
 * The `--position=index --index=<n>` option is advisory: out-of-range values
 * clamp to the start or the end (with a warning) so a concurrent reorder of the
 * queue cannot turn the insertion into a hard failure. Display numbers are
 * never used to identify queued tasks for mutation.
 */
final class BacklogTaskCreateCommand extends AbstractBacklogCommand
{
    private const POSITION_START = 'start';
    private const POSITION_INDEX = 'index';
    private const POSITION_END = 'end';

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
     * Inserts a new queued task in the todo section.
     *
     * Accepts either a positional `<text>` description (single line, multi-line via
     * embedded newlines) or `--body-file=<path>` for longer multi-line bodies.
     *
     * @param list<string> $commandArgs
     * @param array<string, bool|string|array<bool|string>> $options
     */
    public function handle(array $commandArgs, array $options): void
    {
        $entry = $this->buildEntryFromInput($commandArgs, $options);

        $board = $this->loadBoard();
        $entries = $board->getEntries(BacklogBoard::SECTION_TODO);
        $position = $this->resolveTaskCreatePosition($options, count($entries));
        array_splice($entries, $position, 0, [$entry]);
        $board->setEntries(BacklogBoard::SECTION_TODO, $entries);
        $this->saveBoard($board, BacklogCommandName::TASK_CREATE->value);

        $this->presenter->displaySuccess(sprintf('Added task to the todo section at position %d', $position + 1));
    }

    /**
     * Builds a board entry from the positional arguments or from --body-file.
     *
     * @param array<int, string> $commandArgs
     * @param array<string, bool|string|array<bool|string>> $options
     */
    private function buildEntryFromInput(array $commandArgs, array $options): BoardEntry
    {
        $bodyFile = $options['body-file'] ?? null;

        if ($bodyFile !== null) {
            if (!is_string($bodyFile) || trim($bodyFile) === '') {
                throw new \RuntimeException('Option --body-file requires a non-empty path when provided.');
            }
            if ($commandArgs !== [] && trim(implode(' ', $commandArgs)) !== '') {
                throw new \RuntimeException('task-create does not accept positional <text> when --body-file is used.');
            }
            $resolvedPath = $this->resolveBodyFilePath($bodyFile);
            $contents = file_get_contents($resolvedPath);
            if ($contents === false) {
                throw new \RuntimeException(sprintf('Unable to read --body-file: %s', $bodyFile));
            }
            $lines = preg_split('/\R/', $contents) ?: [];

            return $this->boardService->createEntryFromInputLines($lines);
        }

        $text = implode(' ', $commandArgs);
        if (trim($text) === '') {
            throw new \RuntimeException('This command requires a task description or --body-file=<path>.');
        }

        if (preg_match('/\R/', $text) === 1) {
            $lines = preg_split('/\R/', $text) ?: [];

            return $this->boardService->createEntryFromInputLines($lines);
        }

        return $this->boardService->createEntryFromInput(trim($text));
    }

    /**
     * Resolves a --body-file path against the project root and asserts the file exists.
     */
    private function resolveBodyFilePath(string $bodyFile): string
    {
        $candidate = str_starts_with($bodyFile, '/') ? $bodyFile : $this->projectRoot . '/' . $bodyFile;
        if (!is_file($candidate)) {
            throw new \RuntimeException(sprintf('--body-file path does not exist: %s', $bodyFile));
        }

        return $candidate;
    }

    /**
     * Resolves the 0-based insertion index for task-create from options.
     *
     * @param array<string, bool|string|array<bool|string>> $options
     */
    private function resolveTaskCreatePosition(array $options, int $entryCount): int
    {
        $rawPosition = $options['position'] ?? self::POSITION_END;
        if (is_array($rawPosition)) {
            throw new \RuntimeException('Option --position cannot be repeated.');
        }
        $position = (string) $rawPosition;
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

        if (!array_key_exists('index', $options)) {
            throw new \RuntimeException('task-create with --position=index requires --index=<1-based-position>.');
        }
        $rawIndex = $options['index'];
        if (is_array($rawIndex)) {
            throw new \RuntimeException('Option --index cannot be repeated.');
        }
        $index = (int) $rawIndex;
        $clamped = max(0, min($entryCount, $index - 1));
        if ($clamped !== $index - 1) {
            $this->presenter->displayLine(sprintf(
                'Warning: --index=%d is out of range (1..%d); inserting at position %d instead.',
                $index,
                max(1, $entryCount + 1),
                $clamped + 1,
            ));
        }

        return $clamped;
    }
}
