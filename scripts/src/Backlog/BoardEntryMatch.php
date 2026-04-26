<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog;

/**
 * Locates one board entry inside a managed backlog section.
 */
final class BoardEntryMatch
{
    public function __construct(
        private string $section,
        private int $index,
        private BoardEntry $entry,
    ) {
    }

    public function getSection(): string
    {
        return $this->section;
    }

    public function getIndex(): int
    {
        return $this->index;
    }

    public function getEntry(): BoardEntry
    {
        return $this->entry;
    }
}
