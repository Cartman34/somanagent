<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Validation\Test;

use Sowapps\SoManAgent\Script\Validation\ScriptExecBitValidator;
use Sowapps\SoManAgent\Script\Validation\GitIndexModeReader;
use Sowapps\SoManAgent\Script\Validation\IndexModeReader;

/**
 * Unit tests for ScriptExecBitValidator.
 */
final class ScriptExecBitValidatorTest
{
    /**
     * Runs all test cases and returns the total number of failures.
     * @api
     */
    public function run(): int
    {
        $failed = 0;

        $failed += $this->testReportsShebangWithoutExecBit();
        $failed += $this->testIgnoresFileWithoutShebang();
        $failed += $this->testAcceptsShebangWithExecBit();
        $failed += $this->testIgnoresMissingFile();
        $failed += $this->testHandlesEmptyList();
        $failed += $this->testReportsWhenIndexModeIsNonExecutable();
        $failed += $this->testAcceptsWhenIndexModeIsExecutableEvenIfFsIsExec();
        $failed += $this->testFsExecAndUntrackedFileIsAccepted();
        $failed += $this->testIntegrationWithRealGitReaderDetectsIndexMismatch();

        return $failed;
    }

    /**
     * A file declaring a shebang but missing the exec bit must be reported.
     */
    private function testReportsShebangWithoutExecBit(): int
    {
        $tempDir = $this->makeTempDir();
        $file = $tempDir . '/runnable-without-exec.php';
        file_put_contents($file, "#!/usr/bin/env php\n<?php\n");
        chmod($file, 0644);

        $missing = (new ScriptExecBitValidator())->findMissingExecBit([$file]);

        $this->cleanup($tempDir);

        if ($missing !== [$file]) {
            echo "FAIL testReportsShebangWithoutExecBit: expected [$file], got " . var_export($missing, true) . "\n";
            return 1;
        }
        return 0;
    }

    /**
     * A file without a shebang must be ignored, even when not executable.
     */
    private function testIgnoresFileWithoutShebang(): int
    {
        $tempDir = $this->makeTempDir();
        $file = $tempDir . '/library.php';
        file_put_contents($file, "<?php\nreturn 1;\n");
        chmod($file, 0644);

        $missing = (new ScriptExecBitValidator())->findMissingExecBit([$file]);

        $this->cleanup($tempDir);

        if ($missing !== []) {
            echo "FAIL testIgnoresFileWithoutShebang: expected [], got " . var_export($missing, true) . "\n";
            return 1;
        }
        return 0;
    }

    /**
     * A file declaring a shebang with the exec bit set must not be reported.
     */
    private function testAcceptsShebangWithExecBit(): int
    {
        $tempDir = $this->makeTempDir();
        $file = $tempDir . '/runnable-with-exec.php';
        file_put_contents($file, "#!/usr/bin/env php\n<?php\n");
        chmod($file, 0755);

        $missing = (new ScriptExecBitValidator())->findMissingExecBit([$file]);

        $this->cleanup($tempDir);

        if ($missing !== []) {
            echo "FAIL testAcceptsShebangWithExecBit: expected [], got " . var_export($missing, true) . "\n";
            return 1;
        }
        return 0;
    }

    /**
     * Missing files must be silently skipped — the caller filters file existence.
     */
    private function testIgnoresMissingFile(): int
    {
        $missing = (new ScriptExecBitValidator())->findMissingExecBit(['/no/such/file.php']);

        if ($missing !== []) {
            echo "FAIL testIgnoresMissingFile: expected [], got " . var_export($missing, true) . "\n";
            return 1;
        }
        return 0;
    }

    /**
     * An empty input list must produce an empty result.
     */
    private function testHandlesEmptyList(): int
    {
        $missing = (new ScriptExecBitValidator())->findMissingExecBit([]);

        if ($missing !== []) {
            echo "FAIL testHandlesEmptyList: expected [], got " . var_export($missing, true) . "\n";
            return 1;
        }
        return 0;
    }

    /**
     * Even when the filesystem reports the file as executable, an index mode of `100644` must be reported.
     *
     * This is the production case that the original filesystem-only validator missed under WSL with
     * `core.filemode = false`.
     */
    private function testReportsWhenIndexModeIsNonExecutable(): int
    {
        $tempDir = $this->makeTempDir();
        $file = $tempDir . '/fs-ok-index-bad.php';
        file_put_contents($file, "#!/usr/bin/env php\n<?php\n");
        chmod($file, 0755);

        $reader = new InMemoryIndexModeReader([$file => '100644']);
        $missing = (new ScriptExecBitValidator($reader))->findMissingExecBit([$file]);

        $this->cleanup($tempDir);

        if ($missing !== [$file]) {
            echo "FAIL testReportsWhenIndexModeIsNonExecutable: expected [$file], got " . var_export($missing, true) . "\n";
            return 1;
        }
        return 0;
    }

    /**
     * When both filesystem and index agree on exec, nothing is reported.
     */
    private function testAcceptsWhenIndexModeIsExecutableEvenIfFsIsExec(): int
    {
        $tempDir = $this->makeTempDir();
        $file = $tempDir . '/fs-ok-index-ok.php';
        file_put_contents($file, "#!/usr/bin/env php\n<?php\n");
        chmod($file, 0755);

        $reader = new InMemoryIndexModeReader([$file => '100755']);
        $missing = (new ScriptExecBitValidator($reader))->findMissingExecBit([$file]);

        $this->cleanup($tempDir);

        if ($missing !== []) {
            echo "FAIL testAcceptsWhenIndexModeIsExecutableEvenIfFsIsExec: expected [], got " . var_export($missing, true) . "\n";
            return 1;
        }
        return 0;
    }

    /**
     * An untracked file (absent from index) is evaluated on the filesystem only; not flagged when fs is exec.
     */
    private function testFsExecAndUntrackedFileIsAccepted(): int
    {
        $tempDir = $this->makeTempDir();
        $file = $tempDir . '/untracked.php';
        file_put_contents($file, "#!/usr/bin/env php\n<?php\n");
        chmod($file, 0755);

        $reader = new InMemoryIndexModeReader([]); // no index entry
        $missing = (new ScriptExecBitValidator($reader))->findMissingExecBit([$file]);

        $this->cleanup($tempDir);

        if ($missing !== []) {
            echo "FAIL testFsExecAndUntrackedFileIsAccepted: expected [], got " . var_export($missing, true) . "\n";
            return 1;
        }
        return 0;
    }

    /**
     * Integration test: real GitIndexModeReader wired into the validator must flag the WSL scenario.
     *
     * Exercises the exact chain that `ValidateFilesRunner` runs in production: a temp git repo,
     * a shebang-bearing file staged in `100644`, the filesystem chmod'd to `0755`, and the
     * validator asked to assess the file. The expected outcome is that the validator reports
     * the path despite `is_executable()` returning true — proving that the index-aware path is
     * actually plugged in, not just the in-memory unit tests above.
     */
    private function testIntegrationWithRealGitReaderDetectsIndexMismatch(): int
    {
        if (!$this->isGitAvailable()) {
            echo "SKIP testIntegrationWithRealGitReaderDetectsIndexMismatch (git binary not installed)\n";
            return 0;
        }

        $repoDir = $this->makeTempDir();
        $relative = 'fake-script.php';
        $file = $repoDir . '/' . $relative;

        $this->execInDir('git init -q', $repoDir);
        $this->execInDir('git config user.email test@example.com', $repoDir);
        $this->execInDir('git config user.name test', $repoDir);
        // core.filemode = false reproduces the WSL scenario the feature targets.
        $this->execInDir('git config core.filemode false', $repoDir);
        file_put_contents($file, "#!/usr/bin/env php\n<?php\n");
        chmod($file, 0644);
        $this->execInDir('git add ' . escapeshellarg($relative), $repoDir);
        // Filesystem is now exec but index still stores 100644.
        chmod($file, 0755);

        $validator = new ScriptExecBitValidator(new GitIndexModeReader($repoDir));
        $missing = $validator->findMissingExecBit([$file]);

        $this->cleanup($repoDir);

        if ($missing !== [$file]) {
            echo "FAIL testIntegrationWithRealGitReaderDetectsIndexMismatch: expected [$file], got " . var_export($missing, true) . "\n";
            return 1;
        }

        echo "OK testIntegrationWithRealGitReaderDetectsIndexMismatch\n";
        return 0;
    }

    /**
     * Runs a shell command inside a directory and aborts the test fixture setup on failure.
     */
    private function execInDir(string $command, string $cwd): void
    {
        $full = 'cd ' . escapeshellarg($cwd) . ' && ' . $command;
        exec($full . ' 2>&1', $output, $exit);
        if ($exit !== 0) {
            throw new \RuntimeException(sprintf("Command failed: %s\n%s", $full, implode("\n", $output)));
        }
    }

    /**
     * Returns true when the `git` binary is reachable on the local PATH.
     */
    private function isGitAvailable(): bool
    {
        exec('which git 2>/dev/null', $output, $exit);

        return $exit === 0;
    }

    /**
     * Creates an isolated temporary directory under sys_get_temp_dir() for fixture files.
     */
    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/script-exec-bit-test-' . bin2hex(random_bytes(6));
        mkdir($dir, 0755, true);
        return $dir;
    }

    /**
     * Removes the temporary directory and its contents.
     */
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
            if (is_dir($path) && !is_link($path)) {
                $this->cleanup($path);
                continue;
            }
            @chmod($path, 0o600);
            @unlink($path);
        }
        @rmdir($dir);
    }
}

/**
 * In-memory IndexModeReader for tests — returns a preset map without invoking git.
 */
final class InMemoryIndexModeReader implements IndexModeReader
{
    /**
     * @param array<string, string> $modesByFile Preset mode map indexed by file path
     */
    public function __construct(private array $modesByFile) {}

    /**
     * @param list<string> $files
     * @return array<string, string>
     */
    public function readModes(array $files): array
    {
        $modes = [];
        foreach ($files as $file) {
            if (isset($this->modesByFile[$file])) {
                $modes[$file] = $this->modesByFile[$file];
            }
        }

        return $modes;
    }
}
