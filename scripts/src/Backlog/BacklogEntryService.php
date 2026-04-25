<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog;

use SoManAgent\Script\TextSlugger;

/**
 * Handles reusable backlog feature/task entry rules and mutations.
 */
final class BacklogEntryService
{
    public const ENTRY_KIND_FEATURE = 'feature';
    public const ENTRY_KIND_TASK = 'task';
    public const BRANCH_TYPE_FEAT = 'feat';
    public const BRANCH_TYPE_FIX = 'fix';

    private const TASK_CREATE_TYPE_SHORT_PREFIX_PATTERN = '/^\[(feat|fix)\](.*)$/i';
    private const TASK_SCOPE_PREFIX_PATTERN = '/^\[([A-Za-z0-9_-]+)\]\[([A-Za-z0-9_-]+)\]\s*(.+)$/';
    private const TASK_CONTRIBUTION_PREFIX_PATTERN = '/^\s*-\s*\[task:([a-z0-9-]+)\]\s*(.+)$/';

    private TextSlugger $featureSlugger;
    private BacklogEntryResolver $entryResolver;

    public function __construct(TextSlugger $featureSlugger, BacklogEntryResolver $entryResolver)
    {
        $this->featureSlugger = $featureSlugger;
        $this->entryResolver = $entryResolver;
    }

    public function nextTaskText(BacklogBoard $board): string
    {
        $target = $board->findNextBookableTask(false);
        if ($target === null) {
            throw new \RuntimeException('No non-reserved task available in the todo section.');
        }

        return $target['entry']->getText();
    }

    public function normalizeFeatureSlug(string $text): string
    {
        return $this->featureSlugger->slugify($text);
    }

    public function entryKind(BoardEntry $entry): string
    {
        $kind = trim((string) $entry->getMeta('kind'));
        if ($kind !== '') {
            return $kind;
        }

        return $entry->hasMeta('task') ? self::ENTRY_KIND_TASK : self::ENTRY_KIND_FEATURE;
    }

    public function isFeatureEntry(BoardEntry $entry): bool
    {
        return $this->entryKind($entry) === self::ENTRY_KIND_FEATURE;
    }

    public function isTaskEntry(BoardEntry $entry): bool
    {
        return $this->entryKind($entry) === self::ENTRY_KIND_TASK;
    }

    /**
     * @return array{featureGroup: string, task: string, text: string}|null
     */
    public function extractScopedTaskMetadata(string $text): ?array
    {
        if (preg_match(self::TASK_SCOPE_PREFIX_PATTERN, trim($text), $matches) !== 1) {
            return null;
        }

        return [
            'featureGroup' => $this->normalizeFeatureSlug($matches[1]),
            'task' => $this->normalizeFeatureSlug($matches[2]),
            'text' => trim($matches[3]),
        ];
    }

    public function featureStage(BoardEntry $entry): string
    {
        return BacklogBoard::entryStage($entry) ?? BacklogBoard::STAGE_IN_PROGRESS;
    }

    public function taskDeclaredBranchType(BoardEntry $entry, string $command): string
    {
        $type = trim((string) $entry->getMeta('type'));
        if ($type === '') {
            return '';
        }
        if (!in_array($type, [self::BRANCH_TYPE_FEAT, self::BRANCH_TYPE_FIX], true)) {
            throw new \RuntimeException(sprintf(
                '%s only accepts [feat] or [fix] task prefixes.',
                $command,
            ));
        }

        return $type;
    }

    public function validateTaskEntryTypeMetadata(BoardEntry $entry, string $command): void
    {
        $this->taskDeclaredBranchType($entry, $command);
    }

    public function resolveFeatureStartBranchType(BoardEntry $entry, ?BoardEntry $parentFeatureEntry, string $override): string
    {
        $declaredType = $this->taskDeclaredBranchType($entry, BacklogCommandName::FEATURE_START->value);

        if ($parentFeatureEntry !== null) {
            $parentBranch = $parentFeatureEntry->getMeta('branch') ?? '';
            $parentBranchType = $this->detectBranchType($parentBranch);
            if ($parentBranchType === '') {
                throw new \RuntimeException('Parent feature metadata is incomplete: missing branch type.');
            }
            if ($override !== '' && $override !== $parentBranchType) {
                throw new \RuntimeException(sprintf(
                    'feature-start cannot use branch type %s because parent feature branch already uses %s.',
                    $override,
                    $parentBranchType,
                ));
            }
            if ($declaredType !== '' && $declaredType !== $parentBranchType) {
                throw new \RuntimeException(sprintf(
                    'feature-start cannot start task type %s in feature branch type %s.',
                    $declaredType,
                    $parentBranchType,
                ));
            }

            return $parentBranchType;
        }

        if ($override !== '' && $declaredType !== '' && $override !== $declaredType) {
            throw new \RuntimeException(sprintf(
                'feature-start cannot use branch type %s because the queued task declares type %s.',
                $override,
                $declaredType,
            ));
        }

        if ($declaredType !== '') {
            return $declaredType;
        }
        if ($override !== '') {
            return $override;
        }

        return self::BRANCH_TYPE_FEAT;
    }

    public function assertFeatureEntry(BoardEntry $entry, string $command): void
    {
        if ($this->isFeatureEntry($entry)) {
            return;
        }

        throw new \RuntimeException(sprintf(
            '%s only applies to kind=feature entries.',
            $command,
        ));
    }

    public function assertTaskEntry(BoardEntry $entry, string $command): void
    {
        if ($this->isTaskEntry($entry)) {
            return;
        }

        throw new \RuntimeException(sprintf(
            '%s only applies to kind=task entries.',
            $command,
        ));
    }

    public function assertTaskSlugAvailableForFeature(
        BacklogBoard $board,
        BoardEntry $featureEntry,
        string $feature,
        string $task,
        string $command,
    ): void {
        foreach ($this->entryResolver->findTaskEntriesByFeature($board, $feature) as $match) {
            if (($match['entry']->getMeta('task') ?? '') === $task) {
                throw new \RuntimeException(sprintf(
                    '%s cannot continue because task %s is already active in feature %s.',
                    $command,
                    $task,
                    $feature,
                ));
            }
        }

        foreach ($this->featureContributionBlocks($featureEntry) as $block) {
            if ($block['task'] === $task) {
                throw new \RuntimeException(sprintf(
                    '%s cannot continue because task %s is already recorded in feature %s.',
                    $command,
                    $task,
                    $feature,
                ));
            }
        }
    }

    public function createTaskEntryFromInput(string $text): BoardEntry
    {
        $normalizedText = trim($text);
        if (preg_match(self::TASK_CREATE_TYPE_SHORT_PREFIX_PATTERN, $normalizedText, $matches) === 1) {
            $entry = new BoardEntry(trim($matches[2]), [], ['type' => strtolower($matches[1])]);
            $this->validateTaskEntryTypeMetadata($entry, BacklogCommandName::TASK_CREATE->value);

            return $entry;
        }

        $entry = BoardEntry::fromLines(['- ' . $normalizedText]);
        $this->validateTaskEntryTypeMetadata($entry, BacklogCommandName::TASK_CREATE->value);

        return $entry;
    }

    /**
     * @param array<int, array{index: int, entry: BoardEntry}> $reserved
     */
    public function removeReservedTasks(BacklogBoard $board, array $reserved): void
    {
        $entries = $board->getEntries(BacklogBoard::SECTION_TODO);
        $indexes = array_map(static fn(array $item): int => $item['index'], $reserved);
        rsort($indexes);

        foreach ($indexes as $index) {
            array_splice($entries, $index, 1);
        }

        $board->setEntries(BacklogBoard::SECTION_TODO, array_values($entries));
    }

    public function removeActiveEntryAt(BacklogBoard $board, int $index): void
    {
        $entries = $board->getEntries(BacklogBoard::SECTION_ACTIVE);
        array_splice($entries, $index, 1);
        $board->setEntries(BacklogBoard::SECTION_ACTIVE, array_values($entries));
    }

    /**
     * @return array{index: int, entry: BoardEntry}|null
     */
    public function nextTodoTask(BacklogBoard $board): ?array
    {
        $entries = $board->getEntries(BacklogBoard::SECTION_TODO);
        if ($entries === []) {
            return null;
        }

        return ['index' => 0, 'entry' => $entries[0]];
    }

    public function detectBranchType(string $branch): string
    {
        if (preg_match('/^(feat|fix)\//', $branch, $matches) === 1) {
            return $matches[1];
        }

        return '';
    }

    /**
     * @return array<int, array{task: string, text: string, extraLines: array<string>}>
     */
    public function featureContributionBlocks(BoardEntry $featureEntry): array
    {
        $blocks = [];
        $currentIndex = null;

        foreach ($featureEntry->getExtraLines() as $line) {
            if (preg_match(self::TASK_CONTRIBUTION_PREFIX_PATTERN, trim($line), $matches) === 1) {
                $blocks[] = ['task' => $matches[1], 'text' => trim($matches[2]), 'extraLines' => []];
                $currentIndex = array_key_last($blocks);
                continue;
            }

            if ($currentIndex === null) {
                continue;
            }

            $blocks[$currentIndex]['extraLines'][] = '  ' . ltrim($line);
        }

        return $blocks;
    }

    /**
     * @param array<int, array{task: string, text: string, extraLines: array<string>}> $blocks
     */
    public function rebuildFeatureFromContributionBlocks(BoardEntry $featureEntry, array $blocks): void
    {
        $lines = [];

        foreach ($blocks as $block) {
            $lines[] = sprintf('  - [task:%s] %s', $block['task'], $block['text']);
            foreach ($block['extraLines'] as $line) {
                $lines[] = '    ' . ltrim($line);
            }
        }

        $featureEntry->setExtraLines($lines);
    }

    public function appendTaskContribution(BoardEntry $featureEntry, BoardEntry $taskEntry): void
    {
        $blocks = $this->featureContributionBlocks($featureEntry);
        $task = (string) ($taskEntry->getMeta('task') ?? '');
        foreach ($blocks as $block) {
            if ($block['task'] === $task) {
                return;
            }
        }

        $blocks[] = [
            'task' => $task,
            'text' => $taskEntry->getText(),
            'extraLines' => $taskEntry->getExtraLines(),
        ];
        $this->rebuildFeatureFromContributionBlocks($featureEntry, $blocks);
    }

    public function removeTaskContribution(BoardEntry $featureEntry, BoardEntry $taskEntry): bool
    {
        $blocks = $this->featureContributionBlocks($featureEntry);
        $remaining = [];
        $removed = false;
        $task = (string) ($taskEntry->getMeta('task') ?? '');

        foreach ($blocks as $block) {
            if (!$removed && $block['task'] === $task) {
                $removed = true;
                continue;
            }

            $remaining[] = $block;
        }

        if (!$removed) {
            return false;
        }

        $this->rebuildFeatureFromContributionBlocks($featureEntry, $remaining);

        return $remaining !== [];
    }

    public function invalidateFeatureReviewState(BoardEntry $featureEntry): void
    {
        if ($this->featureStage($featureEntry) !== BacklogBoard::STAGE_IN_PROGRESS) {
            $featureEntry->setMeta('stage', BacklogBoard::STAGE_IN_PROGRESS);
        }
    }

    public function taskReviewKey(BoardEntry $entry): string
    {
        return sprintf(
            '%s/%s',
            $entry->getMeta('feature') ?? '-',
            $entry->getMeta('task') ?? '-',
        );
    }
}
