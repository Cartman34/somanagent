<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Validation;

/**
 * Filesystem repair counterpart of {@see ScriptExecBitValidator}.
 *
 * Receives a precomputed list of files that the validator already flagged as
 * missing the exec bit, and restores `+x` on each one. The validator stays
 * authoritative for *detection*; the fixer only applies the deterministic
 * filesystem mutation derived from it.
 *
 * Git index mode (`100644` vs `100755`) is intentionally out of scope here —
 * the fixer only touches the working tree mode, which is what `is_executable()`
 * reads. Callers that also need to commit the change (typical CLI usage) must
 * pair this with `git update-index --chmod=+x` themselves.
 */
final class ExecBitFixer
{
    /**
     * Applies `chmod +x` to each file. Skips dry-run mutations.
     *
     * @param list<string> $files Files already filtered by ScriptExecBitValidator::findMissingExecBit()
     * @param bool $dryRun When true, return the list as-if fixed without touching the filesystem
     * @return list<string> Paths whose mode was changed (or would be changed under dry-run)
     */
    public function fix(array $files, bool $dryRun = false): array
    {
        $fixed = [];

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            if (is_executable($file)) {
                continue;
            }
            if (!$dryRun && !chmod($file, $this->desiredMode($file))) {
                continue;
            }
            $fixed[] = $file;
        }

        return $fixed;
    }

    /**
     * Returns the current mode OR-ed with the user/group/other exec bits.
     *
     * Preserves any existing setuid/setgid/sticky bits and read/write permissions
     * already on the file; only the three exec bits are forced on.
     */
    private function desiredMode(string $file): int
    {
        $current = fileperms($file);
        if ($current === false) {
            return 0o755;
        }

        return ($current & 0o7777) | 0o111;
    }
}
