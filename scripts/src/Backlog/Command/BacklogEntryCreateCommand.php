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
use SoManAgent\Script\Backlog\Service\BodyFilePathResolver;

/**
 * Inserts a new backlog entry into the todo section.
 *
 * Requires `--feature=<slug>` and `--body-file=<path>`. Optional `--task=<slug>` for
 * scoped child tasks and `--type=feat|fix|tech` for branch-type metadata.
 * The `--position=index --index=<n>` option is advisory: out-of-range values clamp to
 * the start or the end (with a warning) so a concurrent reorder cannot turn insertion
 * into a hard failure.
 */
final class BacklogEntryCreateCommand extends AbstractBacklogCommand
{
    private const POSITION_START = 'start';
    private const POSITION_INDEX = 'index';
    private const POSITION_END = 'end';

    private BodyFilePathResolver $bodyFilePathResolver;

    /**
     * @param BacklogPresenter $presenter
     * @param bool $dryRun
     * @param string $projectRoot
     * @param BacklogBoardService $boardService
     * @param BodyFilePathResolver $bodyFilePathResolver
     */
    public function __construct(BacklogPresenter $presenter, bool $dryRun, string $projectRoot, BacklogBoardService $boardService, BodyFilePathResolver $bodyFilePathResolver)
    {
        parent::__construct($presenter, $dryRun, $projectRoot, $boardService);
        $this->bodyFilePathResolver = $bodyFilePathResolver;
    }

    /**
     * Inserts a new queued entry in the todo section.
     *
     * @param list<string> $commandArgs
     * @param array<string, bool|string|array<bool|string>> $options
     */
    public function handle(array $commandArgs, array $options): void
    {
        $entry = $this->buildEntryFromInput($commandArgs, $options);

        $board = $this->loadBoard();
        $this->revertParentIfInReview($board, $entry);
        $entries = $board->getEntries(BacklogBoard::SECTION_TODO);
        $position = $this->resolveEntryCreatePosition($options, count($entries));
        array_splice($entries, $position, 0, [$entry]);
        $board->setEntries(BacklogBoard::SECTION_TODO, $entries);
        $this->saveBoard($board, BacklogCommandName::ENTRY_CREATE->value);

        $this->presenter->displaySuccess(sprintf('Added entry to the todo section at position %d', $position + 1));
    }

    /**
     * Reverts the parent feature to development when the entry is a scoped child task
     * and the parent is in a review-like stage.
     */
    private function revertParentIfInReview(BacklogBoard $board, BoardEntry $entry): void
    {
        if ($entry->getTask() === null) {
            return;
        }
        $feature = $entry->getFeature();
        if ($feature === null) {
            return;
        }

        $parentMatch = $this->boardService->findParentFeatureEntry($board, $feature);
        if ($parentMatch === null || !$this->boardService->isFeatureInReviewLikeStage($parentMatch->getEntry())) {
            return;
        }

        $previousStage = $this->boardService->getStageLabel(
            $this->boardService->getFeatureStage($parentMatch->getEntry())
        );
        $this->boardService->invalidateFeatureReviewState($parentMatch->getEntry());
        $this->presenter->displayLine(sprintf(
            'Feature %s reverted to development because task %s/%s was added (was %s).',
            $feature,
            $feature,
            $entry->getTask(),
            $previousStage,
        ));
    }

    /**
     * Builds a board entry from --feature / --task / --type / --body-file options.
     *
     * @param array<int, string> $commandArgs
     * @param array<string, bool|string|array<bool|string>> $options
     */
    private function buildEntryFromInput(array $commandArgs, array $options): BoardEntry
    {
        if ($commandArgs !== [] && trim(implode(' ', $commandArgs)) !== '') {
            throw new \RuntimeException('entry-create no longer accepts inline task text. Use --body-file=<path> instead.');
        }

        $featureRaw = $options['feature'] ?? null;
        if ($featureRaw === null) {
            throw new \RuntimeException('entry-create requires --body-file=<path>.');
        }
        if (!is_string($featureRaw) || trim($featureRaw) === '') {
            throw new \RuntimeException('entry-create requires --feature=<slug>.');
        }
        $feature = $this->boardService->normalizeFeatureSlug(trim($featureRaw));
        if ($feature === '') {
            throw new \RuntimeException('entry-create requires an explicit [feature-slug] scope.');
        }

        $taskRaw = $options['task'] ?? null;
        $task = null;
        if ($taskRaw !== null) {
            if (!is_string($taskRaw) || trim($taskRaw) === '') {
                throw new \RuntimeException('Option --task requires a non-empty slug when provided.');
            }
            $task = $this->boardService->normalizeFeatureSlug(trim($taskRaw));
        }

        $typeRaw = $options['type'] ?? null;
        $type = null;
        if ($typeRaw !== null) {
            if (!is_string($typeRaw)) {
                throw new \RuntimeException('Option --type cannot be repeated.');
            }
            $taskType = \SoManAgent\Script\Backlog\Enum\BacklogTaskType::tryFromToken(trim($typeRaw));
            if ($taskType === null) {
                throw new \RuntimeException(sprintf(
                    'Unknown --type=%s. Supported types: %s.',
                    trim($typeRaw),
                    \SoManAgent\Script\Backlog\Enum\BacklogTaskType::tokenList(),
                ));
            }
            $type = $taskType->value;
        }

        $bodyFile = $options['body-file'] ?? null;
        if ($bodyFile === null) {
            throw new \RuntimeException('entry-create requires --body-file=<path>.');
        }
        if (!is_string($bodyFile) || trim($bodyFile) === '') {
            throw new \RuntimeException('Option --body-file requires a non-empty path when provided.');
        }
        $resolvedPath = $this->bodyFilePathResolver->resolve($bodyFile);
        $contents = file_get_contents($resolvedPath);
        if ($contents === false) {
            throw new \RuntimeException(sprintf('Unable to read --body-file: %s', $bodyFile));
        }
        $rawLines = preg_split('/\R/', $contents) ?: [];
        $bodyLines = $this->parseBodyFileLines($rawLines);

        $title = array_shift($bodyLines) ?? '';
        if (trim($title) === '') {
            throw new \RuntimeException('Task body cannot be empty.');
        }

        $extraLines = array_map(
            static fn (string $l) => $l !== '' ? '  ' . $l : '',
            $bodyLines,
        );

        $entry = new BoardEntry($title, $extraLines);
        $entry->setFeature($feature);
        $entry->setTask($task);
        $entry->setType($type);

        return $entry;
    }

    /**
     * Strips leading bullet (`- `) from the first content line and trims blank edges.
     *
     * @param array<string> $rawLines
     * @return array<string>
     */
    private function parseBodyFileLines(array $rawLines): array
    {
        $cleaned = array_map('rtrim', $rawLines);
        while ($cleaned !== [] && $cleaned[0] === '') {
            array_shift($cleaned);
        }
        while ($cleaned !== [] && end($cleaned) === '') {
            array_pop($cleaned);
        }
        if ($cleaned !== [] && preg_match('/^-\s+(.*)$/', $cleaned[0], $m) === 1) {
            $cleaned[0] = $m[1];
        }

        return $cleaned;
    }

    /**
     * Resolves the 0-based insertion index for entry-create from options.
     *
     * @param array<string, bool|string|array<bool|string>> $options
     */
    private function resolveEntryCreatePosition(array $options, int $entryCount): int
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
            throw new \RuntimeException('entry-create --position must be start, index, or end.');
        }

        if ($position === self::POSITION_START) {
            return 0;
        }

        if ($position === self::POSITION_END) {
            return $entryCount;
        }

        if (!array_key_exists('index', $options)) {
            throw new \RuntimeException('entry-create with --position=index requires --index=<1-based-position>.');
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
