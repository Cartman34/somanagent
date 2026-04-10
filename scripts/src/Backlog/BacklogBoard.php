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
    public const SECTION_IN_PROGRESS = "En d\u{00E9}veloppement";
    public const SECTION_IN_REVIEW = "\u{00C0} relire";
    public const SECTION_REJECTED = "Rejet\u{00E9}es";
    public const SECTION_APPROVED = "Approuv\u{00E9}es";

    /** @var array<int, string> */
    private const TASK_SECTIONS = [
        self::SECTION_TODO,
        self::SECTION_IN_PROGRESS,
        self::SECTION_IN_REVIEW,
        self::SECTION_REJECTED,
        self::SECTION_APPROVED,
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
     * @return array{section: string, index: int, entry: BoardEntry}|null
     */
    public function findFeature(string $feature): ?array
    {
        foreach ([self::SECTION_IN_PROGRESS, self::SECTION_IN_REVIEW, self::SECTION_REJECTED, self::SECTION_APPROVED] as $section) {
            foreach ($this->getEntries($section) as $index => $entry) {
                if ($entry->getMeta('feature') === $feature) {
                    return ['section' => $section, 'index' => $index, 'entry' => $entry];
                }
            }
        }

        return null;
    }

    /**
     * @return array<int, array{section: string, index: int, entry: BoardEntry}>
     */
    public function findFeaturesByAgent(string $agent): array
    {
        $matches = [];

        foreach ([self::SECTION_IN_PROGRESS, self::SECTION_IN_REVIEW, self::SECTION_REJECTED, self::SECTION_APPROVED] as $section) {
            foreach ($this->getEntries($section) as $index => $entry) {
                if ($entry->getMeta('agent') === $agent) {
                    $matches[] = ['section' => $section, 'index' => $index, 'entry' => $entry];
                }
            }
        }

        return $matches;
    }

    /**
     * @return array<int, array{index: int, entry: BoardEntry}>
     */
    public function findReservedTasks(?string $agent = null, ?string $feature = null): array
    {
        $matches = [];

        foreach ($this->getEntries(self::SECTION_TODO) as $index => $entry) {
            if ($entry->getMeta('agent') === null) {
                continue;
            }

            if ($agent !== null && $entry->getMeta('agent') !== $agent) {
                continue;
            }

            if ($feature !== null && $entry->getMeta('feature') !== $feature) {
                continue;
            }

            $matches[] = ['index' => $index, 'entry' => $entry];
        }

        return $matches;
    }

    /**
     * @return array{index: int, entry: BoardEntry}|null
     */
    public function findNextBookableTask(bool $force = false): ?array
    {
        foreach ($this->getEntries(self::SECTION_TODO) as $index => $entry) {
            if ($force || $entry->getMeta('agent') === null) {
                return ['index' => $index, 'entry' => $entry];
            }
        }

        return null;
    }

    /**
     * Moves one active feature entry from its current section to another managed section.
     */
    public function moveFeature(string $feature, string $targetSection): void
    {
        $match = $this->findFeature($feature);
        if ($match === null) {
            throw new \RuntimeException("Feature not found in backlog: $feature");
        }

        $entries = $this->getEntries($match['section']);
        $entry = $entries[$match['index']];
        array_splice($entries, $match['index'], 1);
        $this->setEntries($match['section'], array_values($entries));

        $targetEntries = $this->getEntries($targetSection);
        $targetEntries[] = $entry;
        $this->setEntries($targetSection, $targetEntries);
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

        $entries = $this->getEntries($match['section']);
        array_splice($entries, $match['index'], 1);
        $this->setEntries($match['section'], array_values($entries));
    }

    /**
     * Removes all task reservations for one agent, optionally filtered by feature.
     */
    public function clearReservations(string $agent, ?string $feature = null): void
    {
        $entries = $this->getEntries(self::SECTION_TODO);

        foreach ($entries as $entry) {
            if ($entry->getMeta('agent') !== $agent) {
                continue;
            }

            if ($feature !== null && $entry->getMeta('feature') !== $feature) {
                continue;
            }

            $entry->unsetMeta('agent');
            $entry->unsetMeta('feature');
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
                        default => ['feature', 'agent', 'branch', 'base', 'blocked'],
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

        foreach (self::TASK_SECTIONS as $section) {
            $this->taskSections[$section] = $this->parseEntries($this->rawSections[$section] ?? []);
        }
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
}
