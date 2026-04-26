<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog;

/**
 * Maintains reviewer findings keyed by one local review target in the local review file.
 */
final class BacklogReviewFile
{
    public const SECTION_RULES = "Règles d'usage";
    public const SECTION_CURRENT_REVIEW = "Revue en cours";
    public const EMPTY_REVIEW_TEXT = 'Aucune review en cours.';

    private string $path;

    private string $header;

    /** @var array<string, array<string>> */
    private array $sections = [];

    /** @var array<string, array<string>> */
    private array $reviews = [];

    /**
     * Loads one local backlog review file and parses its managed review sections.
     */
    public function __construct(string $path)
    {
        $this->path = $path;
        $this->load();
    }

    /**
     * @param array<string> $items
     */
    public function setReview(string $key, array $items): void
    {
        $this->reviews[$key] = $items;
    }

    /**
     * Removes one stored review section by its local review target key.
     */
    public function clearReview(string $key): void
    {
        unset($this->reviews[$key]);
    }

    /**
     * Clears every managed review section from the local review file model.
     */
    public function reset(): void
    {
        $this->reviews = [];
    }

    /**
     * Rewrites the local review file with normalized managed review sections.
     */
    public function save(): void
    {
        $chunks = [$this->header, ''];

        foreach ([self::SECTION_RULES, self::SECTION_CURRENT_REVIEW] as $section) {
            $chunks[] = '## ' . $section;
            $chunks[] = '';

            if ($section === self::SECTION_CURRENT_REVIEW) {
                if ($this->reviews === []) {
                    $chunks[] = self::EMPTY_REVIEW_TEXT;
                } else {
                    ksort($this->reviews);
                    $first = true;
                    foreach ($this->reviews as $key => $items) {
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
                foreach ($this->normalizeSectionLines($this->sections[$section] ?? []) as $line) {
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
            throw new \RuntimeException("Unable to read backlog review file: {$this->path}");
        }

        $this->header = array_shift($lines);

        $currentSection = null;
        $currentFeature = null;

        foreach ($lines as $line) {
            if (preg_match('/^## (.+)$/', $line, $matches) === 1) {
                $currentSection = $matches[1];
                $currentFeature = null;
                $this->sections[$currentSection] = [];
                continue;
            }

            if ($currentSection === null) {
                continue;
            }

            if ($currentSection === self::SECTION_CURRENT_REVIEW && preg_match('/^### (.+)$/', $line, $matches) === 1) {
                $currentFeature = $matches[1];
                $this->reviews[$currentFeature] = [];
                continue;
            }

            if ($currentSection === self::SECTION_CURRENT_REVIEW && $currentFeature !== null) {
                if ($line !== '') {
                    $this->reviews[$currentFeature][] = $line;
                }
                continue;
            }

            $this->sections[$currentSection][] = $line;
        }

        if (($this->reviews[self::EMPTY_REVIEW_TEXT] ?? null) !== null) {
            unset($this->reviews[self::EMPTY_REVIEW_TEXT]);
        }

        foreach ($this->sections as $section => $lines) {
            $this->sections[$section] = $this->normalizeSectionLines($lines);
        }
    }

    /**
     * @param array<string> $lines
     * @return array<string>
     */
    private function normalizeSectionLines(array $lines): array
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
