<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Service;

use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Model\BacklogReviewFile;
use SoManAgent\Script\Backlog\Model\BoardEntry;
use SoManAgent\Script\Backlog\Model\BoardEntryMatch;
use SoManAgent\Script\Backlog\Enum\BacklogCommandName;
use SoManAgent\Script\Backlog\Enum\BacklogMetaValue;
use SoManAgent\Script\Client\FilesystemClientInterface;
use SoManAgent\Script\TextSlugger;

/**
 * Service for orchestrating Backlog Board operations and entry resolution.
 */
final class BacklogBoardService
{
    public const ENTRY_KIND_FEATURE = 'feature';
    public const ENTRY_KIND_TASK = 'task';
    public const BRANCH_TYPE_FEAT = 'feat';
    public const BRANCH_TYPE_FIX = 'fix';

    private const TASK_CREATE_TYPE_SHORT_PREFIX_PATTERN = '/^\[(feat|fix)\](.*)$/i';
    private const TASK_SCOPE_PREFIX_PATTERN = '/^\[([A-Za-z0-9_-]+)\]\[([A-Za-z0-9_-]+)\]\s*(.+)$/';
    private const TASK_CONTRIBUTION_PREFIX_PATTERN = '/^\s*-\s*\[task:([a-z0-9-]+)\]\s*(.+)$/';

    private const META_BLOCK_PREFIX = '  meta:';
    private const META_LINE_PREFIX = '    ';

    private const LEGACY_SECTION_IN_PROGRESS = "En développement";
    private const LEGACY_SECTION_IN_REVIEW = "À relire";
    private const LEGACY_SECTION_REJECTED = "Rejetées";
    private const LEGACY_SECTION_APPROVED = "Approuvées";

    private TextSlugger $featureSlugger;

    private FilesystemClientInterface $fs;

    private bool $dryRun;

    public function __construct(TextSlugger $featureSlugger, FilesystemClientInterface $fs, bool $dryRun)
    {
        $this->featureSlugger = $featureSlugger;
        $this->fs = $fs;
        $this->dryRun = $dryRun;
    }

    /* --- Board Persistence Operations --- */

    public function loadBoard(string $path): BacklogBoard
    {
        $content = $this->fs->getFileContents($path);
        $lines = preg_split('/\R/', $content) ?: [];
        if ($lines === []) {
            throw new \RuntimeException("Unable to read backlog board: {$path}");
        }

        $title = array_shift($lines);
        $board = new BacklogBoard($path, $title);

        $currentSection = null;
        $rawSections = [];
        $sectionOrder = [];

        foreach ($lines as $line) {
            if (preg_match('/^## (.+)$/', $line, $matches) === 1) {
                $currentSection = $matches[1];
                $sectionOrder[] = $currentSection;
                $rawSections[$currentSection] = [];
                continue;
            }

            if ($currentSection === null) {
                continue;
            }

            $rawSections[$currentSection][] = $line;
        }

        foreach ($rawSections as $section => $sectionLines) {
            $rawSections[$section] = $this->sanitizeSectionLines($sectionLines);
        }

        $board->setRawSections($rawSections);
        $board->setSectionOrder($sectionOrder);

        $this->updateManagedSectionOrder($board);

        $board->setEntries(BacklogBoard::SECTION_TODO, $this->parseEntriesFromSectionLines($rawSections[BacklogBoard::SECTION_TODO] ?? []));
        $board->setEntries(BacklogBoard::SECTION_ACTIVE, $this->parseActiveEntries($board));

        return $board;
    }

    public function saveBoard(BacklogBoard $board): void
    {
        if ($this->dryRun) {
            return;
        }

        $chunks = [$board->getTitle(), ''];
        $rawSections = $board->getRawSections();

        foreach ($board->getSectionOrder() as $section) {
            $chunks[] = '## ' . $section;
            $chunks[] = '';

            if (in_array($section, [BacklogBoard::SECTION_TODO, BacklogBoard::SECTION_ACTIVE], true)) {
                $entries = $board->getEntries($section);
                if ($entries !== []) {
                    $order = match ($section) {
                        BacklogBoard::SECTION_TODO => ['agent', 'feature'],
                        default => ['kind', 'stage', 'feature', 'task', 'agent', 'branch', 'feature-branch', 'base', 'pr', 'blocked'],
                    };

                    foreach ($entries as $entry) {
                        foreach ($this->formatEntryToLines($entry, $order) as $line) {
                            $chunks[] = $line;
                        }
                    }
                }
            } else {
                foreach ($this->sanitizeSectionLines($rawSections[$section] ?? []) as $line) {
                    $chunks[] = $line;
                }
            }

            $chunks[] = '';
        }

        $this->fs->writeFilePath($board->getPath(), rtrim(implode("\n", $chunks)) . "\n");
    }

    /* --- Review File Persistence Operations --- */

    public function loadReviewFile(string $path): BacklogReviewFile
    {
        $content = $this->fs->getFileContents($path);
        $lines = preg_split('/\R/', $content) ?: [];
        if ($lines === []) {
            throw new \RuntimeException("Unable to read backlog review file: {$path}");
        }

        $header = array_shift($lines);
        $reviewFile = new BacklogReviewFile($path, $header);

        $currentSection = null;
        $currentFeature = null;
        $sections = [];
        $reviews = [];

        foreach ($lines as $line) {
            if (preg_match('/^## (.+)$/', $line, $matches) === 1) {
                $currentSection = $matches[1];
                $currentFeature = null;
                $sections[$currentSection] = [];
                continue;
            }

            if ($currentSection === null) {
                continue;
            }

            if ($currentSection === BacklogReviewFile::SECTION_CURRENT_REVIEW && preg_match('/^### (.+)$/', $line, $matches) === 1) {
                $currentFeature = $matches[1];
                $reviews[$currentFeature] = [];
                continue;
            }

            if ($currentSection === BacklogReviewFile::SECTION_CURRENT_REVIEW && $currentFeature !== null) {
                if ($line !== '') {
                    $reviews[$currentFeature][] = $line;
                }
                continue;
            }

            $sections[$currentSection][] = $line;
        }

        if (($reviews[BacklogReviewFile::EMPTY_REVIEW_TEXT] ?? null) !== null) {
            unset($reviews[BacklogReviewFile::EMPTY_REVIEW_TEXT]);
        }

        foreach ($sections as $section => $sectionLines) {
            $sections[$section] = $this->sanitizeReviewLines($sectionLines);
        }

        $reviewFile->setSections($sections);
        $reviewFile->setReviews($reviews);

        return $reviewFile;
    }

    public function saveReviewFile(BacklogReviewFile $reviewFile): void
    {
        if ($this->dryRun) {
            return;
        }

        $chunks = [$reviewFile->getHeader(), ''];
        $sections = $reviewFile->getSections();
        $reviews = $reviewFile->getReviews();

        foreach ([BacklogReviewFile::SECTION_RULES, BacklogReviewFile::SECTION_CURRENT_REVIEW] as $section) {
            $chunks[] = '## ' . $section;
            $chunks[] = '';

            if ($section === BacklogReviewFile::SECTION_CURRENT_REVIEW) {
                if ($reviews === []) {
                    $chunks[] = BacklogReviewFile::EMPTY_REVIEW_TEXT;
                } else {
                    ksort($reviews);
                    $first = true;
                    foreach ($reviews as $key => $items) {
                        if (!$first) {
                            $chunks[] = '';
                        }
                        $first = false;
                        $chunks[] = '### ' . $key;
                        $chunks[] = '';
                        foreach ($items as $item) {
                            $chunks[] = $item;
                        }
                    }
                }
            } else {
                foreach ($this->sanitizeReviewLines($sections[$section] ?? []) as $line) {
                    $chunks[] = $line;
                }
            }

            $chunks[] = '';
        }

        $this->fs->writeFilePath($reviewFile->getPath(), rtrim(implode("\n", $chunks)) . "\n");
    }

    /* --- Entry Normalization & Classification --- */

    public function normalizeFeatureSlug(string $text): string
    {
        return $this->featureSlugger->slugify($text);
    }

    public function getEntryKind(BoardEntry $entry): string
    {
        $kind = $entry->getKind();
        if ($kind !== null) {
            return $kind;
        }

        return $entry->getTask() !== null ? self::ENTRY_KIND_TASK : self::ENTRY_KIND_FEATURE;
    }

    public function checkIsFeatureEntry(BoardEntry $entry): bool
    {
        return $this->getEntryKind($entry) === self::ENTRY_KIND_FEATURE;
    }

    public function checkIsTaskEntry(BoardEntry $entry): bool
    {
        return $this->getEntryKind($entry) === self::ENTRY_KIND_TASK;
    }

    public function getFeatureStage(BoardEntry $entry): string
    {
        return $this->getEntryStage($entry) ?? BacklogBoard::STAGE_IN_PROGRESS;
    }

    /* --- Entry Resolution (from Board) --- */

    public function resolveFeature(BacklogBoard $board, string $feature): BoardEntryMatch
    {
        $match = $this->findParentFeatureEntry($board, $feature);
        if ($match === null) {
            throw new \RuntimeException("Feature not found: {$feature}");
        }

        return $match;
    }

    public function findParentFeatureEntry(BacklogBoard $board, string $feature): ?BoardEntryMatch
    {
        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $index => $entry) {
            if (!$this->checkIsFeatureEntry($entry)) {
                continue;
            }
            if ($entry->getFeature() !== $feature) {
                continue;
            }

            return new BoardEntryMatch(BacklogBoard::SECTION_ACTIVE, $index, $entry);
        }

        return null;
    }

    public function resolveTaskByReference(BacklogBoard $board, string $reference, string $command): BoardEntryMatch
    {
        $normalizedReference = trim($reference);
        if ($normalizedReference === '') {
            throw new \RuntimeException(sprintf('%s requires a task reference.', $command));
        }

        if (str_contains($normalizedReference, '/')) {
            [$feature, $task] = array_pad(explode('/', $normalizedReference, 2), 2, '');
            $feature = $this->normalizeFeatureSlug($feature);
            $task = $this->normalizeFeatureSlug($task);

            foreach ($this->findTaskEntriesByFeature($board, $feature) as $match) {
                if ($match->getEntry()->getTask() === $task) {
                    return $match;
                }
            }

            throw new \RuntimeException(sprintf('Task not found: %s/%s', $feature, $task));
        }

        $task = $this->normalizeFeatureSlug($normalizedReference);
        $matches = $this->findTaskEntriesByTaskSlug($board, $task);
        if ($matches === []) {
            throw new \RuntimeException(sprintf('Task not found: %s', $task));
        }
        if (count($matches) > 1) {
            throw new \RuntimeException(sprintf(
                '%s requires <feature/task> because task slug %s is not unique.',
                $command,
                $task,
            ));
        }

        return $matches[0];
    }

    /**
     * @return array<int, BoardEntryMatch>
     */
    public function fetchFeaturesByStage(BacklogBoard $board, string $stage): array
    {
        $normalizedStage = $this->getNormalizedStage($stage);
        if ($normalizedStage === null) {
            return [];
        }

        $matches = [];

        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $index => $entry) {
            if (!$this->checkIsFeatureEntry($entry)) {
                continue;
            }
            if ($this->getEntryStage($entry) !== $normalizedStage) {
                continue;
            }

            $matches[] = new BoardEntryMatch(BacklogBoard::SECTION_ACTIVE, $index, $entry);
        }

        return $matches;
    }

    /**
     * @return array<int, BoardEntryMatch>
     */
    public function findTaskEntriesByFeature(BacklogBoard $board, string $feature): array
    {
        $matches = [];
        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $index => $entry) {
            if (!$this->checkIsTaskEntry($entry)) {
                continue;
            }
            if ($entry->getFeature() !== $feature) {
                continue;
            }

            $matches[] = new BoardEntryMatch(BacklogBoard::SECTION_ACTIVE, $index, $entry);
        }

        return $matches;
    }

    /**
     * @return array<int, BoardEntryMatch>
     */
    public function findTaskEntriesByTaskSlug(BacklogBoard $board, string $task): array
    {
        $matches = [];
        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $index => $entry) {
            if (!$this->checkIsTaskEntry($entry)) {
                continue;
            }
            if ($entry->getTask() !== $task) {
                continue;
            }

            $matches[] = new BoardEntryMatch(BacklogBoard::SECTION_ACTIVE, $index, $entry);
        }

        return $matches;
    }

    public function resolveSingleTaskForAgent(BacklogBoard $board, string $agent): BoardEntryMatch
    {
        $matches = $this->findTaskEntriesByAgent($board, $agent);
        if ($matches === []) {
            throw new \RuntimeException("Agent {$agent} has no active task.");
        }
        if (count($matches) > 1) {
            throw new \RuntimeException("Agent {$agent} has multiple active tasks.");
        }

        return $matches[0];
    }

    /**
     * @return array<int, BoardEntryMatch>
     */
    public function findTaskEntriesByAgent(BacklogBoard $board, string $agent): array
    {
        $matches = [];
        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $index => $entry) {
            if (!$this->checkIsTaskEntry($entry) || $entry->getAgent() !== $agent) {
                continue;
            }

            $matches[] = new BoardEntryMatch(BacklogBoard::SECTION_ACTIVE, $index, $entry);
        }

        return $matches;
    }

    public function resolveSingleFeatureForAgent(BacklogBoard $board, string $agent): BoardEntryMatch
    {
        $matches = $this->findFeatureEntriesByAgent($board, $agent);
        if ($matches === []) {
            throw new \RuntimeException("Agent {$agent} has no active feature.");
        }
        if (count($matches) > 1) {
            throw new \RuntimeException("Agent {$agent} has multiple active features.");
        }

        return $matches[0];
    }

    /**
     * @return array<int, BoardEntryMatch>
     */
    public function findFeatureEntriesByAgent(BacklogBoard $board, string $agent): array
    {
        $matches = [];
        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $index => $entry) {
            if (!$this->checkIsFeatureEntry($entry) || $entry->getAgent() !== $agent) {
                continue;
            }

            $matches[] = new BoardEntryMatch(BacklogBoard::SECTION_ACTIVE, $index, $entry);
        }

        return $matches;
    }

    /**
     * @return array<int, BoardEntryMatch>
     */
    public function fetchReservedTasks(BacklogBoard $board, ?string $agent = null, ?string $feature = null): array
    {
        $matches = [];

        foreach ($board->getEntries(BacklogBoard::SECTION_TODO) as $index => $entry) {
            if ($entry->getAgent() === null) {
                continue;
            }

            if ($agent !== null && $entry->getAgent() !== $agent) {
                continue;
            }

            if ($feature !== null && $entry->getFeature() !== $feature) {
                continue;
            }

            $matches[] = new BoardEntryMatch(BacklogBoard::SECTION_TODO, $index, $entry);
        }

        return $matches;
    }

    public function fetchNextBookableTask(BacklogBoard $board, bool $force = false): ?BoardEntryMatch
    {
        foreach ($board->getEntries(BacklogBoard::SECTION_TODO) as $index => $entry) {
            if ($force || $entry->getAgent() === null) {
                return new BoardEntryMatch(BacklogBoard::SECTION_TODO, $index, $entry);
            }
        }

        return null;
    }

    /* --- Board Entry Markdown Parsing & Formatting --- */

    /**
     * @param array<string> $lines
     */
    public function parseEntryFromLines(array $lines): BoardEntry
    {
        if ($lines === []) {
            throw new \RuntimeException('Backlog entry cannot be empty.');
        }

        $firstLine = array_shift($lines);
        if (!str_starts_with($firstLine, '- ')) {
            throw new \RuntimeException("Backlog entry must start with '- '.");
        }

        $body = substr($firstLine, 2);
        $metadata = [];

        [$metadata, $body] = $this->parseMetadataPrefix($metadata, $body);

        if ($lines !== [] && preg_match('/^\s+\[([a-z0-9_-]+):([^\]]+)\]/', $lines[0]) === 1) {
            $metadataLine = ltrim(array_shift($lines));
            [$metadata, $metadataLine] = $this->parseMetadataPrefix($metadata, $metadataLine);
            if (trim($metadataLine) !== '') {
                array_unshift($lines, '  ' . $metadataLine);
            }
        }

        [$lines, $trailingMetadata] = $this->parseTrailingMetaBlock($lines);
        foreach ($trailingMetadata as $key => $value) {
            $metadata[$key] = $value;
        }

        $entry = new BoardEntry(ltrim($body), $lines);
        $this->hydrateEntryFromMetadata($entry, $metadata);

        return $entry;
    }

    /**
     * @param array<string> $metadataOrder
     * @return array<string>
     */
    public function formatEntryToLines(BoardEntry $entry, array $metadataOrder = []): array
    {
        $metadata = $this->extractMetadataFromEntry($entry);
        $ordered = [];

        foreach ($metadataOrder as $key) {
            if (isset($metadata[$key])) {
                $ordered[$key] = $metadata[$key];
            }
        }

        foreach ($metadata as $key => $value) {
            if (!isset($ordered[$key])) {
                $ordered[$key] = $value;
            }
        }

        $lines = ['- ' . $entry->getText()];
        foreach ($entry->getExtraLines() as $line) {
            $lines[] = $line;
        }

        if ($ordered !== []) {
            $lines[] = self::META_BLOCK_PREFIX;
            foreach ($ordered as $key => $value) {
                $lines[] = sprintf('%s%s: %s', self::META_LINE_PREFIX, $key, $value);
            }
        }

        return $lines;
    }

    /**
     * @param array<string, string> $metadata
     */
    public function hydrateEntryFromMetadata(BoardEntry $entry, array $metadata): void
    {
        $entry->setAgent($this->sanitizeString($metadata[BoardEntry::META_AGENT] ?? null));
        $entry->setBase($this->sanitizeString($metadata[BoardEntry::META_BASE] ?? null));
        $entry->setBlocked($this->sanitizeString($metadata[BoardEntry::META_BLOCKED] ?? null) === BacklogMetaValue::YES->value);
        $entry->setBranch($this->sanitizeString($metadata[BoardEntry::META_BRANCH] ?? null));
        $entry->setFeature($this->sanitizeString($metadata[BoardEntry::META_FEATURE] ?? null));
        $entry->setFeatureBranch($this->sanitizeString($metadata[BoardEntry::META_FEATURE_BRANCH] ?? null));
        $entry->setKind($this->sanitizeString($metadata[BoardEntry::META_KIND] ?? null));
        $entry->setPr($this->sanitizeString($metadata[BoardEntry::META_PR] ?? null));
        $entry->setStage($this->sanitizeString($metadata[BoardEntry::META_STAGE] ?? null));
        $entry->setTask($this->sanitizeString($metadata[BoardEntry::META_TASK] ?? null));
        $entry->setType($this->sanitizeString($metadata[BoardEntry::META_TYPE] ?? null));

        $knownKeys = [
            BoardEntry::META_AGENT, BoardEntry::META_BASE, BoardEntry::META_BLOCKED, BoardEntry::META_BRANCH,
            BoardEntry::META_FEATURE, BoardEntry::META_FEATURE_BRANCH, BoardEntry::META_KIND,
            BoardEntry::META_PR, BoardEntry::META_STAGE, BoardEntry::META_TASK, BoardEntry::META_TYPE,
        ];

        $extraMetadata = array_diff_key($metadata, array_flip($knownKeys));
        $extraMetadata = array_filter(
            array_map($this->sanitizeString(...), $extraMetadata),
            static fn(?string $value): bool => $value !== null
        );
        $entry->setExtraMetadata($extraMetadata);
    }

    /**
     * @return array<string, string>
     */
    public function extractMetadataFromEntry(BoardEntry $entry): array
    {
        $metadata = $entry->getExtraMetadata();

        $mappings = [
            BoardEntry::META_AGENT => $entry->getAgent(),
            BoardEntry::META_BASE => $entry->getBase(),
            BoardEntry::META_BRANCH => $entry->getBranch(),
            BoardEntry::META_FEATURE => $entry->getFeature(),
            BoardEntry::META_FEATURE_BRANCH => $entry->getFeatureBranch(),
            BoardEntry::META_KIND => $entry->getKind(),
            BoardEntry::META_PR => $entry->getPr(),
            BoardEntry::META_STAGE => $entry->getStage(),
            BoardEntry::META_TASK => $entry->getTask(),
            BoardEntry::META_TYPE => $entry->getType(),
        ];

        foreach ($mappings as $key => $value) {
            if ($value !== null) {
                $metadata[$key] = $value;
            }
        }

        if ($entry->checkIsBlocked()) {
            $metadata[BoardEntry::META_BLOCKED] = BacklogMetaValue::YES->value;
        }

        return $metadata;
    }

    /**
     * @param array<string, string> $metadata
     * @return array{0: array<string, string>, 1: string}
     */
    private function parseMetadataPrefix(array $metadata, string $text): array
    {
        while (preg_match('/^\[([a-z0-9_-]+):([^\]]+)\]/', $text, $matches) === 1) {
            $metadata[$matches[1]] = $matches[2];
            $text = substr($text, strlen($matches[0]));
        }

        return [$metadata, ltrim($text)];
    }

    /**
     * @param array<string> $lines
     * @return array{0: array<string>, 1: array<string, string>}
     */
    private function parseTrailingMetaBlock(array $lines): array
    {
        $metaStartIndex = array_search(self::META_BLOCK_PREFIX, $lines, true);
        if ($metaStartIndex === false) {
            return [$lines, []];
        }

        $metadata = [];
        $metaLines = array_slice($lines, $metaStartIndex + 1);
        if ($metaLines === []) {
            return [$lines, []];
        }

        foreach ($metaLines as $line) {
            if (!str_starts_with($line, self::META_LINE_PREFIX)) {
                return [$lines, []];
            }

            $body = substr($line, strlen(self::META_LINE_PREFIX));
            if (preg_match('/^([a-z0-9_-]+):\s*(.+)$/', $body, $matches) !== 1) {
                return [$lines, []];
            }

            $metadata[$matches[1]] = $matches[2];
        }

        return [array_slice($lines, 0, $metaStartIndex), $metadata];
    }

    public function sanitizeString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /* --- Stage Logic --- */

    public function getNormalizedStage(?string $stage): ?string
    {
        return match (trim((string) $stage)) {
            BacklogBoard::STAGE_IN_PROGRESS, self::LEGACY_SECTION_IN_PROGRESS => BacklogBoard::STAGE_IN_PROGRESS,
            BacklogBoard::STAGE_IN_REVIEW, self::LEGACY_SECTION_IN_REVIEW => BacklogBoard::STAGE_IN_REVIEW,
            BacklogBoard::STAGE_REJECTED, self::LEGACY_SECTION_REJECTED => BacklogBoard::STAGE_REJECTED,
            BacklogBoard::STAGE_APPROVED, self::LEGACY_SECTION_APPROVED => BacklogBoard::STAGE_APPROVED,
            default => null,
        };
    }

    public function getStageLabel(string $stage): string
    {
        return match ($this->getNormalizedStage($stage)) {
            BacklogBoard::STAGE_IN_PROGRESS => self::LEGACY_SECTION_IN_PROGRESS,
            BacklogBoard::STAGE_IN_REVIEW => self::LEGACY_SECTION_IN_REVIEW,
            BacklogBoard::STAGE_REJECTED => self::LEGACY_SECTION_REJECTED,
            BacklogBoard::STAGE_APPROVED => self::LEGACY_SECTION_APPROVED,
            default => $stage,
        };
    }

    /**
     * @return array<int, string>
     */
    public function getActiveStages(): array
    {
        return [
            BacklogBoard::STAGE_IN_PROGRESS,
            BacklogBoard::STAGE_IN_REVIEW,
            BacklogBoard::STAGE_REJECTED,
            BacklogBoard::STAGE_APPROVED,
        ];
    }

    public function getEntryStage(BoardEntry $entry): ?string
    {
        return $this->getNormalizedStage($entry->getStage());
    }

    /* --- Mutations & Complex Logic --- */

    public function createEntryFromInput(string $text): BoardEntry
    {
        $normalizedText = trim($text);
        if (preg_match(self::TASK_CREATE_TYPE_SHORT_PREFIX_PATTERN, $normalizedText, $matches) === 1) {
            $entry = new BoardEntry(trim($matches[2]));
            $entry->setType(strtolower($matches[1]));

            return $entry;
        }

        return $this->parseEntryFromLines(['- ' . $normalizedText]);
    }

    public function fetchNextTodoTask(BacklogBoard $board): ?BoardEntryMatch
    {
        $entries = $board->getEntries(BacklogBoard::SECTION_TODO);
        if ($entries === []) {
            return null;
        }

        return new BoardEntryMatch(BacklogBoard::SECTION_TODO, 0, $entries[0]);
    }

    public function removeReservedTasks(BacklogBoard $board, array $reserved): void
    {
        $entries = $board->getEntries(BacklogBoard::SECTION_TODO);
        $indexes = array_map(static fn(BoardEntryMatch $item): int => $item->getIndex(), $reserved);
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

    public function updateFeatureStage(BacklogBoard $board, string $feature, string $stage): void
    {
        $match = $this->resolveFeature($board, $feature);
        $normalizedStage = $this->getNormalizedStage($stage);
        if ($normalizedStage === null) {
            throw new \RuntimeException("Unknown feature stage: {$stage}");
        }

        $match->getEntry()->setStage($normalizedStage);
    }

    public function deleteFeature(BacklogBoard $board, string $feature): void
    {
        $match = $this->findParentFeatureEntry($board, $feature);
        if ($match === null) {
            return;
        }

        $entries = $board->getEntries($match->getSection());
        array_splice($entries, $match->getIndex(), 1);
        $board->setEntries($match->getSection(), array_values($entries));
    }

    public function clearAgentReservations(BacklogBoard $board, string $agent, ?string $feature = null): void
    {
        $entries = $board->getEntries(BacklogBoard::SECTION_TODO);

        foreach ($entries as $entry) {
            if ($entry->getAgent() !== $agent) {
                continue;
            }

            if ($feature !== null && $entry->getFeature() !== $feature) {
                continue;
            }

            $entry->setAgent(null);
            $entry->setFeature(null);
        }

        $board->setEntries(BacklogBoard::SECTION_TODO, $entries);
    }

    public function appendTaskContribution(BoardEntry $featureEntry, BoardEntry $taskEntry): void
    {
        $blocks = $this->getFeatureContributionBlocks($featureEntry);
        $task = (string) ($taskEntry->getTask() ?? '');
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
        $blocks = $this->getFeatureContributionBlocks($featureEntry);
        $remaining = [];
        $removed = false;
        $task = (string) ($taskEntry->getTask() ?? '');

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

    /* --- Validations & Helpers --- */

    public function assertNoActiveTasksForFeature(BacklogBoard $board, string $feature, string $command): void
    {
        if ($this->findTaskEntriesByFeature($board, $feature) !== []) {
            throw new \RuntimeException(sprintf(
                '%s cannot continue while feature %s still has active task branches.',
                $command,
                $feature,
            ));
        }
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

    /**
     * @return array<int, array{task: string, text: string, extraLines: array<string>}>
     */
    public function getFeatureContributionBlocks(BoardEntry $featureEntry): array
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

    private function rebuildFeatureFromContributionBlocks(BoardEntry $featureEntry, array $blocks): void
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

    public function getTaskReviewKey(BoardEntry $entry): string
    {
        return sprintf(
            '%s/%s',
            $entry->getFeature() ?? '-',
            $entry->getTask() ?? '-',
        );
    }

    public function resolveFeatureStartBranchType(BoardEntry $first, ?BoardEntry $parent, string $override): string
    {
        if ($override !== '') {
            return $override;
        }
        if ($first->getType() !== null) {
            return $first->getType();
        }
        if ($parent !== null && $parent->getType() !== null) {
            return $parent->getType();
        }
        return self::BRANCH_TYPE_FEAT;
    }

    public function invalidateFeatureReviewState(BoardEntry $featureEntry): void
    {
        if ($this->getFeatureStage($featureEntry) !== BacklogBoard::STAGE_IN_PROGRESS) {
            $featureEntry->setStage(BacklogBoard::STAGE_IN_PROGRESS);
        }
    }

    public function assertTaskSlugAvailableForFeature(BacklogBoard $board, BoardEntry $featureEntry, string $feature, string $task, string $command): void
    {
        foreach ($this->findTaskEntriesByFeature($board, $feature) as $match) {
            if ($match->getEntry()->getTask() === $task) {
                throw new \RuntimeException(sprintf('%s: Task slug %s is already used for feature %s.', $command, $task, $feature));
            }
        }
    }

    /**
     * @param array<string> $lines
     * @return array<BoardEntry>
     */
    private function parseEntriesFromSectionLines(array $lines): array
    {
        $entries = [];
        $buffer = [];

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, '- ')) {
                if ($buffer !== []) {
                    $entries[] = $this->parseEntryFromLines($buffer);
                }
                $buffer = [$line];
                continue;
            }

            if ($buffer !== []) {
                $buffer[] = $line;
            }
        }

        if ($buffer !== []) {
            $entries[] = $this->parseEntryFromLines($buffer);
        }

        return $entries;
    }

    /**
     * @return array<BoardEntry>
     */
    private function parseActiveEntries(BacklogBoard $board): array
    {
        $entries = [];
        $rawSections = $board->getRawSections();

        foreach ($this->parseEntriesFromSectionLines($rawSections[BacklogBoard::SECTION_ACTIVE] ?? []) as $entry) {
            $entry->setStage($this->getEntryStage($entry) ?? BacklogBoard::STAGE_IN_PROGRESS);
            $entries[] = $entry;
        }

        foreach ($this->getLegacyStageSections() as $section => $stage) {
            foreach ($this->parseEntriesFromSectionLines($rawSections[$section] ?? []) as $entry) {
                $entry->setStage($this->getEntryStage($entry) ?? $stage);
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @return array<string, string>
     */
    private function getLegacyStageSections(): array
    {
        return [
            self::LEGACY_SECTION_IN_PROGRESS => BacklogBoard::STAGE_IN_PROGRESS,
            self::LEGACY_SECTION_IN_REVIEW => BacklogBoard::STAGE_IN_REVIEW,
            self::LEGACY_SECTION_REJECTED => BacklogBoard::STAGE_REJECTED,
            self::LEGACY_SECTION_APPROVED => BacklogBoard::STAGE_APPROVED,
        ];
    }

    private function updateManagedSectionOrder(BacklogBoard $board): void
    {
        $legacySections = array_keys($this->getLegacyStageSections());
        $newOrder = [];
        $activeInserted = false;

        foreach ($board->getSectionOrder() as $section) {
            if (in_array($section, $legacySections, true)) {
                if (!$activeInserted) {
                    $newOrder[] = BacklogBoard::SECTION_ACTIVE;
                    $activeInserted = true;
                }

                continue;
            }

            $newOrder[] = $section;
            if ($section === BacklogBoard::SECTION_ACTIVE) {
                $activeInserted = true;
            }
        }

        if (!$activeInserted) {
            $todoIndex = array_search(BacklogBoard::SECTION_TODO, $newOrder, true);
            if ($todoIndex === false) {
                $newOrder[] = BacklogBoard::SECTION_ACTIVE;
            } else {
                array_splice($newOrder, $todoIndex + 1, 0, [BacklogBoard::SECTION_ACTIVE]);
            }
        }

        $board->setSectionOrder(array_values(array_unique($newOrder)));
        
        $rawSections = $board->getRawSections();
        $rawSections[BacklogBoard::SECTION_ACTIVE] ??= [];
        $board->setRawSections($rawSections);
    }

    /**
     * Normalizes one raw section to a single blank line boundary with no repeated empty lines.
     *
     * @param array<string> $lines
     * @return array<string>
     */
    private function sanitizeSectionLines(array $lines): array
    {
        $normalized = [];
        $previousBlank = false;

        foreach ($lines as $line) {
            $isBlank = trim($line) === '';
            if ($isBlank) {
                if ($previousBlank) {
                    continue;
                }
                $normalized[] = '';
                $previousBlank = true;

                continue;
            }

            $normalized[] = $line;
            $previousBlank = false;
        }

        while ($normalized !== [] && trim($normalized[0]) === '') {
            array_shift($normalized);
        }

        while ($normalized !== [] && trim($normalized[array_key_last($normalized)]) === '') {
            array_pop($normalized);
        }

        return array_values($normalized);
    }

    /**
     * @param array<string> $lines
     * @return array<string>
     */
    private function sanitizeReviewLines(array $lines): array
    {
        $normalized = [];
        $previousWasBlank = false;

        foreach ($lines as $line) {
            $isBlank = trim($line) === '';
            if ($isBlank) {
                if ($previousWasBlank) {
                    continue;
                }

                $normalized[] = '';
                $previousWasBlank = true;

                continue;
            }

            $normalized[] = $line;
            $previousWasBlank = false;
        }

        while ($normalized !== [] && $normalized[0] === '') {
            array_shift($normalized);
        }

        while ($normalized !== [] && end($normalized) === '') {
            array_pop($normalized);
        }

        return $normalized;
    }
}
