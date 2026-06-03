<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Validation;

/**
 * Contract for resolving the persisted exec-bit state of a file (git index mode by default).
 *
 * Provides a single seam for tests: production wires {@see GitIndexModeReader}, which shells out
 * to `git ls-files --stage`; unit tests pass a hand-built map so they never depend on a real
 * repository.
 */
interface IndexModeReader
{
    /**
     * @param list<string> $files Paths to inspect (absolute or project-relative — implementations normalise)
     * @return array<string, string> Map of path => six-digit octal mode (e.g. `100755`); omitted when not tracked
     */
    public function readModes(array $files): array;
}
