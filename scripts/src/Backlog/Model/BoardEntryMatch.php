<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Model;

use Sowapps\SoManAgent\Script\Backlog\Model\BoardEntry;

/**
 * Locates one board entry inside a managed backlog section.
 */
final class BoardEntryMatch
{
    /**
     * Captures the section name, array index, and matching entry.
     */
    public function __construct(
        private string $section,
        private int $index,
        private BoardEntry $entry,
    ) {
    }

    /**
     * Returns the board section name that contains the matched entry.
     */
    public function getSection(): string
    {
        return $this->section;
    }

    /**
     * Returns the array index of the matched entry within its section.
     */
    public function getIndex(): int
    {
        return $this->index;
    }

    /**
     * Returns the matched board entry.
     */
    public function getEntry(): BoardEntry
    {
        return $this->entry;
    }
}
