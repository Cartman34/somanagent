<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Validation\Test;

use SoManAgent\Script\Validation\IndexModeReader;
use SoManAgent\Script\Validation\ScriptExecBitValidator;

/**
 * Unit tests for ScriptExecBitValidator.
 */
final class ScriptExecBitValidatorTest
{
    /**
     * Runs all test cases and returns the total number of failures.
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
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
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
