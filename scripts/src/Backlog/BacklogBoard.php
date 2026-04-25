<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog;

/**
 * Reads and rewrites the local backlog board while preserving unmanaged sections.
 */
final class BacklogBoard
{
    public const SECTION_TODO = "\u{00C0} faire";
    public const SECTION_ACTIVE = 'Traitement en cours';

    public const STAGE_IN_PROGRESS = 'development';
    public const STAGE_IN_REVIEW = 'review';
    public const STAGE_REJECTED = 'rejected';
    public const STAGE_APPROVED = 'approved';

    private const LEGACY_SECTION_IN_PROGRESS = "En d\u{00E9}veloppement";
    private const LEGACY_SECTION_IN_REVIEW = "\u{00C0} relire";
    private const LEGACY_SECTION_REJECTED = "Rejet\u{00E9}es";
    private const LEGACY_SECTION_APPROVED = "Approuv\u{00E9}es";

    /** @var array<int, string> */
    private const TASK_SECTIONS = [
        self::SECTION_TODO,
        self::SECTION_ACTIVE,
    ];

    private string $path;

    private string $title;

    /** @var array<int, string> */
    private array $sectionOrder = [];

    /** @var array<string, array<string>> */
    private array $rawSections = [];

    /** @var array<string, array<BoardEntry>> */
    private array $taskSections = [];

    /**
     * Loads one local backlog board file and parses its managed sections.
     */
    public function __construct(string $path)
    {
        $this->path = $path;
        $this->load();
    }

    /**
     * @return array<BoardEntry>
     */
    public function getEntries(string $section): array
    {
        return $this->taskSections[$section] ?? [];
    }

    /**
     * @param array<BoardEntry> $entries
     */
    public function setEntries(string $section, array $entries): void
    {
        $this->taskSections[$section] = $entries;
    }

    /**
     * @return array<int, BoardEntryMatch>
     */
    public function findFeaturesByStage(string $stage): array
    {
        $normalizedStage = self::normalizeStage($stage);
        if ($normalizedStage === null) {
            return [];
        }

        $matches = [];

        foreach ($this->getEntries(self::SECTION_ACTIVE) as $index => $entry) {
            if (!$this->isFeatureEntry($entry)) {
                continue;
            }
            if (self::entryStage($entry) !== $normalizedStage) {
                continue;
            }

            $matches[] = new BoardEntryMatch(self::SECTION_ACTIVE, $index, $entry);
        }

        return $matches;
    }

    /**
     */
    public function findFeature(string $feature): ?BoardEntryMatch
    {
        foreach ($this->getEntries(self::SECTION_ACTIVE) as $index => $entry) {
            if (!$this->isFeatureEntry($entry)) {
                continue;
            }
            if ($entry->getFeature() === $feature) {
                return new BoardEntryMatch(self::SECTION_ACTIVE, $index, $entry);
            }
        }

        return null;
    }

    /**
     * @return array<int, BoardEntryMatch>
     */
    public function findFeaturesByAgent(string $agent): array
    {
        $matches = [];

        foreach ($this->getEntries(self::SECTION_ACTIVE) as $index => $entry) {
            if (!$this->isFeatureEntry($entry)) {
                continue;
            }
            if ($entry->getAgent() === $agent) {
                $matches[] = new BoardEntryMatch(self::SECTION_ACTIVE, $index, $entry);
            }
        }

        return $matches;
    }

    /**
     * @return array<int, BoardEntryMatch>
     */
    public function findReservedTasks(?string $agent = null, ?string $feature = null): array
    {
        $matches = [];

        foreach ($this->getEntries(self::SECTION_TODO) as $index => $entry) {
            if ($entry->getAgent() === null) {
                continue;
            }

            if ($agent !== null && $entry->getAgent() !== $agent) {
                continue;
            }

            if ($feature !== null && $entry->getFeature() !== $feature) {
                continue;
            }

            $matches[] = new BoardEntryMatch(self::SECTION_TODO, $index, $entry);
        }

        return $matches;
    }

    /**
     */
    public function findNextBookableTask(bool $force = false): ?BoardEntryMatch
    {
        foreach ($this->getEntries(self::SECTION_TODO) as $index => $entry) {
            if ($force || $entry->getAgent() === null) {
                return new BoardEntryMatch(self::SECTION_TODO, $index, $entry);
            }
        }

        return null;
    }

    /**
     * Updates the normalized workflow stage of one active feature entry.
     */
    public function setFeatureStage(string $feature, string $stage): void
    {
        $match = $this->findFeature($feature);
        if ($match === null) {
            throw new \RuntimeException("Feature not found in backlog: $feature");
        }

        $normalizedStage = self::normalizeStage($stage);
        if ($normalizedStage === null) {
            throw new \RuntimeException("Unknown feature stage: {$stage}");
        }

        $match->getEntry()->setMeta(BoardEntry::META_STAGE, $normalizedStage);
    }

    /**
     * Removes one active feature entry from the managed backlog sections.
     */
    public function removeFeature(string $feature): void
    {
        $match = $this->findFeature($feature);
        if ($match === null) {
            return;
        }

        $entries = $this->getEntries($match->getSection());
        array_splice($entries, $match->getIndex(), 1);
        $this->setEntries($match->getSection(), array_values($entries));
    }

    /**
     * Removes all task reservations for one agent, optionally filtered by feature.
     */
    public function clearReservations(string $agent, ?string $feature = null): void
    {
        $entries = $this->getEntries(self::SECTION_TODO);

        foreach ($entries as $entry) {
            if ($entry->getAgent() !== $agent) {
                continue;
            }

            if ($feature !== null && $entry->getFeature() !== $feature) {
                continue;
            }

            $entry->unsetMeta(BoardEntry::META_AGENT);
            $entry->unsetMeta(BoardEntry::META_FEATURE);
        }

        $this->setEntries(self::SECTION_TODO, $entries);
    }

    /**
     * Rewrites the backlog board file with normalized managed section formatting.
     */
    public function save(): void
    {
        $chunks = [$this->title, ''];

        foreach ($this->sectionOrder as $section) {
            $chunks[] = '## ' . $section;
            $chunks[] = '';

            if (in_array($section, self::TASK_SECTIONS, true)) {
                $entries = $this->getEntries($section);
                if ($entries !== []) {
                    $order = match ($section) {
                        self::SECTION_TODO => ['agent', 'feature'],
                        default => ['kind', 'stage', 'feature', 'task', 'agent', 'branch', 'feature-branch', 'base', 'pr', 'blocked'],
                    };

                    foreach ($entries as $entry) {
                        foreach ($entry->toLines($order) as $line) {
                            $chunks[] = $line;
                        }
                    }
                }
            } else {
                foreach ($this->normalizeSectionLines($this->rawSections[$section] ?? []) as $line) {
                    $chunks[] = $line;
                }
            }

            $chunks[] = '';
        }

        file_put_contents($this->path, rtrim(implode("\n", $chunks)) . "\n");
    }

    private function load(): void
    {
        $lines = file($this->path, FILE_IGNORE_NEW_LINES);
        if ($lines === false || $lines === []) {
            throw new \RuntimeException("Unable to read backlog board: {$this->path}");
        }

        $this->title = array_shift($lines);

        $currentSection = null;
        foreach ($lines as $line) {
            if (preg_match('/^## (.+)$/', $line, $matches) === 1) {
                $currentSection = $matches[1];
                $this->sectionOrder[] = $currentSection;
                $this->rawSections[$currentSection] = [];
                continue;
            }

            if ($currentSection === null) {
                continue;
            }

            $this->rawSections[$currentSection][] = $line;
        }

        foreach ($this->rawSections as $section => $lines) {
            $this->rawSections[$section] = $this->normalizeSectionLines($lines);
        }

        $this->migrateManagedSectionOrder();

        $this->taskSections[self::SECTION_TODO] = $this->parseEntries($this->rawSections[self::SECTION_TODO] ?? []);
        $this->taskSections[self::SECTION_ACTIVE] = $this->loadActiveEntries();
    }

    /**
     * Normalizes one workflow stage value from current or legacy labels.
     */
    public static function normalizeStage(?string $stage): ?string
    {
        return match (trim((string) $stage)) {
            self::STAGE_IN_PROGRESS, self::LEGACY_SECTION_IN_PROGRESS => self::STAGE_IN_PROGRESS,
            self::STAGE_IN_REVIEW, self::LEGACY_SECTION_IN_REVIEW => self::STAGE_IN_REVIEW,
            self::STAGE_REJECTED, self::LEGACY_SECTION_REJECTED => self::STAGE_REJECTED,
            self::STAGE_APPROVED, self::LEGACY_SECTION_APPROVED => self::STAGE_APPROVED,
            default => null,
        };
    }

    /**
     * Returns the human-readable label used in the backlog for one workflow stage.
     */
    public static function stageLabel(string $stage): string
    {
        return match (self::normalizeStage($stage)) {
            self::STAGE_IN_PROGRESS => self::LEGACY_SECTION_IN_PROGRESS,
            self::STAGE_IN_REVIEW => self::LEGACY_SECTION_IN_REVIEW,
            self::STAGE_REJECTED => self::LEGACY_SECTION_REJECTED,
            self::STAGE_APPROVED => self::LEGACY_SECTION_APPROVED,
            default => $stage,
        };
    }

    /**
     * @return array<int, string>
     */
    public static function activeStages(): array
    {
        return [
            self::STAGE_IN_PROGRESS,
            self::STAGE_IN_REVIEW,
            self::STAGE_REJECTED,
            self::STAGE_APPROVED,
        ];
    }

    /**
     * Resolves the normalized workflow stage stored on one backlog entry.
     */
    public static function entryStage(BoardEntry $entry): ?string
    {
        return self::normalizeStage($entry->getStage());
    }

    /**
     * @param array<string> $lines
     * @return array<BoardEntry>
     */
    private function parseEntries(array $lines): array
    {
        $entries = [];
        $buffer = [];

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, '- ')) {
                if ($buffer !== []) {
                    $entries[] = BoardEntry::fromLines($buffer);
                }
                $buffer = [$line];
                continue;
            }

            if ($buffer !== []) {
                $buffer[] = $line;
            }
        }

        if ($buffer !== []) {
            $entries[] = BoardEntry::fromLines($buffer);
        }

        return $entries;
    }

    /**
     * @return array<BoardEntry>
     */
    private function loadActiveEntries(): array
    {
        $entries = [];

        foreach ($this->parseEntries($this->rawSections[self::SECTION_ACTIVE] ?? []) as $entry) {
            $entry->setMeta('stage', self::entryStage($entry) ?? self::STAGE_IN_PROGRESS);
            $entry->unsetMeta('deps');
            $entries[] = $entry;
        }

        foreach ($this->legacyStageSections() as $section => $stage) {
            foreach ($this->parseEntries($this->rawSections[$section] ?? []) as $entry) {
                $entry->setMeta('stage', self::entryStage($entry) ?? $stage);
                $entry->unsetMeta('deps');
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @return array<string, string>
     */
    private function legacyStageSections(): array
    {
        return [
            self::LEGACY_SECTION_IN_PROGRESS => self::STAGE_IN_PROGRESS,
            self::LEGACY_SECTION_IN_REVIEW => self::STAGE_IN_REVIEW,
            self::LEGACY_SECTION_REJECTED => self::STAGE_REJECTED,
            self::LEGACY_SECTION_APPROVED => self::STAGE_APPROVED,
        ];
    }

    private function migrateManagedSectionOrder(): void
    {
        $legacySections = array_keys($this->legacyStageSections());
        $newOrder = [];
        $activeInserted = false;

        foreach ($this->sectionOrder as $section) {
            if (in_array($section, $legacySections, true)) {
                if (!$activeInserted) {
                    $newOrder[] = self::SECTION_ACTIVE;
                    $activeInserted = true;
                }

                continue;
            }

            $newOrder[] = $section;
            if ($section === self::SECTION_ACTIVE) {
                $activeInserted = true;
            }
        }

        if (!$activeInserted) {
            $todoIndex = array_search(self::SECTION_TODO, $newOrder, true);
            if ($todoIndex === false) {
                $newOrder[] = self::SECTION_ACTIVE;
            } else {
                array_splice($newOrder, $todoIndex + 1, 0, [self::SECTION_ACTIVE]);
            }
        }

        $this->sectionOrder = array_values(array_unique($newOrder));
        $this->rawSections[self::SECTION_ACTIVE] ??= [];
    }

    /**
     * Normalizes one raw section to a single blank line boundary with no repeated empty lines.
     *
     * @param array<string> $lines
     * @return array<string>
     */
    private function normalizeSectionLines(array $lines): array
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

    private function isFeatureEntry(BoardEntry $entry): bool
    {
        $kind = $entry->getKind();
        if ($kind !== '') {
            return $kind === 'feature';
        }

        return !$entry->hasMeta('task');
    }
}
