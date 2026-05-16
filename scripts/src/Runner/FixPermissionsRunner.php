<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

use SoManAgent\Script\Validation\ExecBitFixer;
use SoManAgent\Script\Validation\GitIndexModeReader;
use SoManAgent\Script\Validation\ScriptExecBitValidator;

/**
 * Repairs the exec bit on shebang-bearing scripts under `scripts/`.
 *
 * Mirror counterpart of `validate-files.php`'s `ScriptExecBitValidator`:
 * the validator only reports regressions; this runner applies the same
 * detection rule and then performs `chmod +x` plus `git update-index --chmod=+x`
 * so the bit propagates both to the working tree and to the next commit.
 *
 * Use cases:
 * - A new script is added without the exec bit and `validate-files.php` fails;
 *   running this script restores it in one step.
 * - A `git diff --staged` shows a `mode change` regression and needs to be undone.
 *
 * Scope is intentionally narrow: only `scripts/*.php` entrypoints. The runner
 * never recurses, never touches anything outside `scripts/`, and never alters
 * read/write bits — only exec.
 */
final class FixPermissionsRunner extends AbstractScriptRunner
{
    public const NAME = 'fix-permissions';

    protected function getName(): string
    {
        return self::NAME;
    }

    protected function getDescription(): string
    {
        return 'Restore the exec bit on shebang-bearing `scripts/*.php` entrypoints, both on the filesystem and in the git index';
    }

    protected function getOptions(): array
    {
        return $this->getExecutionModeOptions();
    }

    protected function getUsageExamples(): array
    {
        return [
            'php scripts/fix-permissions.php',
            'php scripts/fix-permissions.php --dry-run',
        ];
    }

    /**
     * @param array<string> $args
     */
    public function run(array $args): int
    {
        [, $options] = $this->parseArgs(array_values($args));
        $this->configureExecutionModes($options);

        $candidates = $this->collectScriptEntrypoints();
        if ($candidates === []) {
            echo "No script entrypoints found under scripts/.\n";
            return 0;
        }

        $gitReader = new GitIndexModeReader($this->projectRoot);
        $validator = new ScriptExecBitValidator($gitReader);
        $missing = $validator->findMissingExecBit($candidates);
        if ($missing === []) {
            echo sprintf("All %d script entrypoints already carry the exec bit (filesystem and git index).\n", count($candidates));
            return 0;
        }

        $indexModes = $gitReader->readModes($missing);

        echo sprintf(
            "Found %d shebang-bearing script%s missing the exec bit%s:\n",
            count($missing),
            count($missing) > 1 ? 's' : '',
            $this->dryRun ? ' (dry-run)' : '',
        );

        foreach ($missing as $file) {
            $relative = $this->relativeToProject($file);
            $fsLabel = is_executable($file) ? 'fs OK' : 'fs MISSING';
            $indexMode = $indexModes[$file] ?? null;
            $indexLabel = $indexMode === null
                ? 'index NOT TRACKED'
                : (str_ends_with($indexMode, '755') ? 'index OK' : 'index ' . $indexMode);
            echo sprintf("  - %s (%s, %s)\n", $relative, $fsLabel, $indexLabel);
        }

        if ($this->dryRun) {
            echo "Dry-run: filesystem and git index were not modified.\n";

            return 0;
        }

        (new ExecBitFixer())->fix($missing);
        $trackedMissing = $this->filterTrackedFiles($missing, $indexModes);
        $gitExit = $this->updateGitIndex($trackedMissing);

        if ($gitExit !== 0) {
            echo "Warning: git update-index returned a non-zero exit code; commit may still need a manual run.\n";

            return 1;
        }

        echo sprintf(
            "Restored exec bit on %d script%s (filesystem chmod + git update-index where tracked).\n",
            count($missing),
            count($missing) > 1 ? 's' : '',
        );

        return 0;
    }

    /**
     * Filters out files that git does not track in the index — `git update-index` would fail on them.
     *
     * @param list<string> $files
     * @param array<string, string> $indexModes
     * @return list<string>
     */
    private function filterTrackedFiles(array $files, array $indexModes): array
    {
        $tracked = [];
        foreach ($files as $file) {
            if (isset($indexModes[$file])) {
                $tracked[] = $file;
            }
        }

        return $tracked;
    }

    /**
     * Returns the canonical list of script entrypoint candidates: `scripts/*.php`, sorted.
     *
     * @return list<string>
     */
    private function collectScriptEntrypoints(): array
    {
        $pattern = $this->projectRoot . '/scripts/*.php';
        $files = glob($pattern);
        if ($files === false) {
            return [];
        }
        sort($files);

        return $files;
    }

    /**
     * Runs `git update-index --chmod=+x` on each fixed file with project-relative paths.
     *
     * @param list<string> $files
     */
    private function updateGitIndex(array $files): int
    {
        if ($files === []) {
            return 0;
        }

        $relativeFiles = array_map(fn(string $f): string => $this->relativeToProject($f), $files);
        $command = 'git update-index --chmod=+x ' . implode(' ', array_map('escapeshellarg', $relativeFiles));

        return $this->app->runCommand($command);
    }

    private function relativeToProject(string $file): string
    {
        $prefix = $this->projectRoot . '/';
        if (str_starts_with($file, $prefix)) {
            return substr($file, strlen($prefix));
        }

        return $file;
    }
}
