<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Validation\Test;

use SoManAgent\Script\Validation\ExecBitFixer;

/**
 * Unit tests for ExecBitFixer.
 *
 * Each test operates on temporary files in sys_get_temp_dir() so the real
 * project files are never mutated by the test run.
 */
final class ExecBitFixerTest
{
    /**
     * Runs all test cases and returns the total number of failures.
     * @api
     */
    public function run(): int
    {
        $failed = 0;

        $failed += $this->testRestoresExecBitOnFile();
        $failed += $this->testDryRunListsButDoesNotMutate();
        $failed += $this->testSkipsFilesAlreadyExecutable();
        $failed += $this->testSkipsMissingFiles();
        $failed += $this->testPreservesReadWritePermissions();

        return $failed;
    }

    private function testRestoresExecBitOnFile(): int
    {
        $dir = $this->makeTempDir();
        $file = $dir . '/runnable.php';
        file_put_contents($file, "#!/usr/bin/env php\n<?php\n");
        chmod($file, 0o644);

        $fixed = (new ExecBitFixer())->fix([$file]);

        $isExec = is_executable($file);
        $this->cleanup($dir);

        if ($fixed !== [$file] || !$isExec) {
            echo "FAIL testRestoresExecBitOnFile: fixed=" . var_export($fixed, true) . " isExec={$isExec}\n";
            return 1;
        }

        echo "OK testRestoresExecBitOnFile\n";
        return 0;
    }

    private function testDryRunListsButDoesNotMutate(): int
    {
        $dir = $this->makeTempDir();
        $file = $dir . '/runnable.php';
        file_put_contents($file, "#!/usr/bin/env php\n<?php\n");
        chmod($file, 0o644);

        $fixed = (new ExecBitFixer())->fix([$file], dryRun: true);

        $isExec = is_executable($file);
        $this->cleanup($dir);

        if ($fixed !== [$file] || $isExec) {
            echo "FAIL testDryRunListsButDoesNotMutate: file must remain non-executable in dry-run\n";
            return 1;
        }

        echo "OK testDryRunListsButDoesNotMutate\n";
        return 0;
    }

    private function testSkipsFilesAlreadyExecutable(): int
    {
        $dir = $this->makeTempDir();
        $file = $dir . '/already-exec.php';
        file_put_contents($file, "#!/usr/bin/env php\n<?php\n");
        chmod($file, 0o755);

        $fixed = (new ExecBitFixer())->fix([$file]);

        $this->cleanup($dir);

        if ($fixed !== []) {
            echo "FAIL testSkipsFilesAlreadyExecutable: must not re-fix executable files\n";
            return 1;
        }

        echo "OK testSkipsFilesAlreadyExecutable\n";
        return 0;
    }

    private function testSkipsMissingFiles(): int
    {
        $dir = $this->makeTempDir();
        $missing = $dir . '/does-not-exist.php';

        $fixed = (new ExecBitFixer())->fix([$missing]);

        $this->cleanup($dir);

        if ($fixed !== []) {
            echo "FAIL testSkipsMissingFiles: missing files must be ignored\n";
            return 1;
        }

        echo "OK testSkipsMissingFiles\n";
        return 0;
    }

    private function testPreservesReadWritePermissions(): int
    {
        $dir = $this->makeTempDir();
        $file = $dir . '/read-only.php';
        file_put_contents($file, "#!/usr/bin/env php\n<?php\n");
        // owner-read+exec wanted, group/other read only — start without exec
        chmod($file, 0o400);

        (new ExecBitFixer())->fix([$file]);

        $mode = fileperms($file) & 0o777;
        $this->cleanup($dir);

        // Original 0400 + exec triplet 0111 = 0511
        if ($mode !== 0o511) {
            echo sprintf("FAIL testPreservesReadWritePermissions: expected 0511, got 0%o\n", $mode);
            return 1;
        }

        echo "OK testPreservesReadWritePermissions\n";
        return 0;
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/exec-bit-fixer-test-' . uniqid('', true);
        mkdir($dir, 0o755, true);

        return $dir;
    }

    private function cleanup(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->cleanup($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
