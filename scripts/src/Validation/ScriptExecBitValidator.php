<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Validation;

/**
 * Validates that runnable scripts under `scripts/` carry the exec bit when they declare a shebang.
 *
 * Two dimensions are checked when a {@see GitIndexModeReader} is supplied:
 *  - the filesystem mode, via `is_executable()`;
 *  - the git index mode, via `git ls-files --stage` (must end in `755`).
 *
 * Both matter because under WSL with `core.filemode = false`, a file may be
 * executable on disk while still recorded as `100644` in the index — which is
 * the state a fresh clone would receive on another machine, so the script
 * effectively ships broken. When no git reader is injected, the validator
 * falls back to the filesystem-only check it originally provided.
 */
final class ScriptExecBitValidator
{
    public function __construct(private ?IndexModeReader $gitIndexReader = null) {}

    /**
     * Inspects an explicit list of script files and reports those that declare a shebang but lack the exec bit.
     *
     * A file is reported when it has a `#!` shebang and either:
     *  - is not marked executable on the filesystem, OR
     *  - is recorded in the git index with a non-executable mode (e.g. `100644`).
     *
     * Files without a shebang are skipped silently — only runnable scripts are checked, and the validator
     * has no opinion on non-runnable PHP files (libraries, generated code) that may also live under `scripts/`.
     * Files absent from the git index (untracked, outside the repo) are evaluated on the filesystem only.
     *
     * @param list<string> $files Paths to check. Missing files are ignored — caller is expected to filter first.
     * @return list<string> Paths of shebang-bearing files missing the exec bit on the filesystem or in the git index.
     */
    public function findMissingExecBit(array $files): array
    {
        $shebangFiles = [];
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            if (!$this->hasShebang($file)) {
                continue;
            }
            $shebangFiles[] = $file;
        }

        $indexModes = $this->gitIndexReader?->readModes($shebangFiles) ?? [];

        $missing = [];
        foreach ($shebangFiles as $file) {
            if (!is_executable($file)) {
                $missing[] = $file;
                continue;
            }
            $indexMode = $indexModes[$file] ?? null;
            if ($indexMode !== null && !$this->indexModeHasExecBit($indexMode)) {
                $missing[] = $file;
            }
        }

        return $missing;
    }

    /**
     * Returns true when the git index mode ends in `755`, which marks an executable tree entry.
     */
    private function indexModeHasExecBit(string $mode): bool
    {
        return str_ends_with($mode, '755');
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
