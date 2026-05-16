<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Validation;

/**
 * Validates that runnable scripts under `scripts/` carry the exec bit when they declare a shebang.
 *
 * Cloning the repository preserves git index modes, so the invariant is enforced at the index level
 * via `git update-index --chmod=+x`; this validator catches regressions before they are committed
 * by inspecting the live filesystem state.
 */
final class ScriptExecBitValidator
{
    /**
     * Inspects an explicit list of script files and reports those that declare a shebang but are not executable.
     *
     * Files without a shebang are skipped silently — only runnable scripts are checked, and the validator
     * has no opinion on non-runnable PHP files (libraries, generated code) that may also live under `scripts/`.
     *
     * @param list<string> $files Paths to check. Missing files are ignored — caller is expected to filter first.
     * @return list<string> Paths of files that declare a shebang but do not have the exec bit set.
     */
    public function findMissingExecBit(array $files): array
    {
        $missing = [];

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            if (!$this->hasShebang($file)) {
                continue;
            }
            if (!is_executable($file)) {
                $missing[] = $file;
            }
        }

        return $missing;
    }

    /**
     * Returns true when the file's first line starts with `#!`.
     */
    private function hasShebang(string $file): bool
    {
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            return false;
        }
        $firstLine = fgets($handle, 1024);
        fclose($handle);

        if ($firstLine === false) {
            return false;
        }

        return str_starts_with($firstLine, '#!');
    }
}
