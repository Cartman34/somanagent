<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Command;

use Sowapps\SoManAgent\Script\Backlog\Service\BodyFilePathResolver;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogPresenter;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogBoardService;
use Sowapps\SoManAgent\Script\Backlog\Model\BacklogBoard;
use Sowapps\SoManAgent\Script\Backlog\Enum\BacklogCommandName;
use Sowapps\SoManAgent\Script\Backlog\Model\BoardEntry;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogScopeService;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogConfig;
use Sowapps\SoManAgent\Script\Backlog\Enum\BacklogCliOption;
use Sowapps\SoManAgent\Script\Backlog\Enum\BacklogTaskType;
/**
 * Inserts a new backlog entry into the todo section.
 *
 * Requires `--feature=<slug>`, `--type=feat|fix|tech`, and `--body-file=<path>`.
 * Optional `--task=<slug>` declares a scoped child task. The body file's first
 * non-empty line is the title and must not carry legacy bracket prefixes —
 * those are rejected outright with a clear error pointing back to the CLI options.
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
        $this->validateScope($entry, $board);
        $this->revertParentIfInReview($board, $entry);
        $entries = $board->getEntries(BacklogBoard::SECTION_TODO);
        $position = $this->resolveEntryCreatePosition($options, count($entries));
        array_splice($entries, $position, 0, [$entry]);
        $board->setEntries(BacklogBoard::SECTION_TODO, $entries);
        $this->saveBoard($board, BacklogCommandName::ENTRY_CREATE->value);

        $this->presenter->displaySuccess(sprintf('Added entry to the todo section at position %d', $position + 1));
    }

    /**
     * Validates the scope field of the new entry against the config and, for child tasks, against the parent feature scope.
     *
     * Throws when the scope is the reserved ALL literal, when it is not declared in the config,
     * or when a child task's scope directories are not fully within the parent feature's scope directories.
     */
    private function validateScope(BoardEntry $entry, BacklogBoard $board): void
    {
        $scopeName = $entry->getScope();
        if ($scopeName === null) {
            return;
        }

        if (strtoupper($scopeName) === BacklogScopeService::RESERVED_ALL) {
            throw new \RuntimeException(sprintf(
                'entry-create --scope=%s is reserved and cannot be used as a scope name.',
                $scopeName,
            ));
        }

        $config = new BacklogConfig($this->projectRoot);
        $scopes = $config->getScopes();

        if (!array_key_exists($scopeName, $scopes)) {
            throw new \RuntimeException(sprintf(
                'Unknown --scope=%s. Declared scopes: %s.',
                $scopeName,
                $scopes !== [] ? implode(', ', array_keys($scopes)) : '(none)',
            ));
        }

        // For child tasks: the task scope must be a subset of the parent feature scope.
        if ($entry->getTask() === null) {
            return;
        }

        $feature = $entry->getFeature();
        if ($feature === null) {
            return;
        }

        $parentScopeName = $this->resolveParentFeatureScopeName($board, $feature);
        if ($parentScopeName === null) {
            return; // parent is ALL → any subset is valid
        }

        $scopeService = new BacklogScopeService();
        $parentScopeDirs = $scopeService->resolveScopeDirs($parentScopeName, $scopes);
        $taskScopeDirs = $scopeService->resolveScopeDirs($scopeName, $scopes);

        if ($parentScopeDirs === null || $taskScopeDirs === null) {
            return;
        }

        if (!$scopeService->isTaskScopeWithinFeatureScope($taskScopeDirs, $parentScopeDirs)) {
            throw new \RuntimeException(sprintf(
                'Task scope "%s" (dirs: %s) exceeds parent feature scope "%s" (dirs: %s).',
                $scopeName,
                implode(', ', $taskScopeDirs),
                $parentScopeName,
                implode(', ', $parentScopeDirs),
            ));
        }
    }

    /**
     * Finds the scope name of the parent feature entry, or null when the feature has no scope (ALL).
     *
     * Searches active entries first, then todo entries.
     */
    private function resolveParentFeatureScopeName(BacklogBoard $board, string $feature): ?string
    {
        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $entry) {
            if ($entry->getFeature() === $feature && $entry->getTask() === null) {
                return $entry->getScope();
            }
        }

        foreach ($board->getEntries(BacklogBoard::SECTION_TODO) as $entry) {
            if ($entry->getFeature() === $feature && $entry->getTask() === null) {
                return $entry->getScope();
            }
        }

        return null;
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

        $featureRaw = $options[BacklogCliOption::FEATURE->value] ?? null;
        if ($featureRaw === null) {
            throw new \RuntimeException('entry-create requires --feature=<slug>.');
        }
        if (!is_string($featureRaw) || trim($featureRaw) === '') {
            throw new \RuntimeException('entry-create requires --feature=<slug>.');
        }
        $feature = $this->boardService->normalizeFeatureSlug(trim($featureRaw));
        if ($feature === '') {
            throw new \RuntimeException('entry-create requires an explicit [feature-slug] scope.');
        }

        $taskRaw = $options[BacklogCliOption::TASK->value] ?? null;
        $task = null;
        if ($taskRaw !== null) {
            if (!is_string($taskRaw) || trim($taskRaw) === '') {
                throw new \RuntimeException('Option --task requires a non-empty slug when provided.');
            }
            $task = $this->boardService->normalizeFeatureSlug(trim($taskRaw));
        }

        $typeRaw = $options[BacklogCliOption::TYPE->value] ?? null;
        if ($typeRaw === null) {
            throw new \RuntimeException(sprintf(
                'entry-create requires --type=<%s>.',
                BacklogTaskType::tokenList(),
            ));
        }
        if (!is_string($typeRaw)) {
            throw new \RuntimeException('Option --type cannot be repeated.');
        }
        if (trim($typeRaw) === '') {
            throw new \RuntimeException(sprintf(
                'entry-create requires --type=<%s>.',
                BacklogTaskType::tokenList(),
            ));
        }
        $taskType = BacklogTaskType::tryFromToken(trim($typeRaw));
        if ($taskType === null) {
            throw new \RuntimeException(sprintf(
                'Unknown --type=%s. Supported types: %s.',
                trim($typeRaw),
                BacklogTaskType::tokenList(),
            ));
        }
        $type = $taskType->value;

        $bodyFile = $options[BacklogCliOption::BODY_FILE->value] ?? null;
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
        if (preg_match('/^\s*\[/', $title) === 1) {
            throw new \RuntimeException(
                'Body file title carries legacy bracket prefixes ([…]) — obsolete prefix syntax, use the CLI options --feature, --task, --type instead.',
            );
        }

        $extraLines = array_map(
            static fn (string $l) => $l !== '' ? '  ' . $l : '',
            $bodyLines,
        );

        $scopeRaw = $options[BacklogCliOption::SCOPE->value] ?? null;
        $scope = null;
        if ($scopeRaw !== null) {
            if (!is_string($scopeRaw) || trim($scopeRaw) === '') {
                throw new \RuntimeException('Option --scope requires a non-empty name when provided.');
            }
            $scope = trim($scopeRaw);
        }

        $entry = new BoardEntry($title, $extraLines);
        $entry->setFeature($feature);
        $entry->setTask($task);
        $entry->setScope($scope);
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
        $rawPosition = $options[BacklogCliOption::POSITION->value] ?? self::POSITION_END;
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

        if (!array_key_exists(BacklogCliOption::INDEX->value, $options)) {
            throw new \RuntimeException('entry-create with --position=index requires --index=<1-based-position>.');
        }
        $rawIndex = $options[BacklogCliOption::INDEX->value];
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
