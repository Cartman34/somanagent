<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Setup\Test;

/**
 * Subprocess integration tests for scripts/setup.php.
 *
 * Spawns the setup.php script as a child process to verify CLI behaviour without
 * requiring Docker or actual package installation. Covers:
 *   - Help display (runner-level and command-level)
 *   - --preview-only on install (lockfile empty = nothing to install)
 *   - --dry-run on install
 *   - Mutual exclusion of --preview-only and --dry-run
 *   - Error on missing lockfile
 *   - Unknown subcommand error
 *   - Not-yet-implemented subcommands
 */
final class SetupRunnerTest
{
    private string $projectRoot;

    /**
     * Resolves the project root from the test file location.
     */
    public function __construct()
    {
        $this->projectRoot = dirname(__DIR__, 4);
    }

    /**
     * Runs all tests and returns the number of failures.
     */
    public function run(): int
    {
        $failed = 0;

        $failed += $this->testHelpDisplay();
        $failed += $this->testInstallCommandHelp();
        $failed += $this->testInstallPreviewOnlyWithEmptyLockfile();
        $failed += $this->testInstallDryRunWithEmptyLockfile();
        $failed += $this->testMutualExclusionFlags();
        $failed += $this->testUnknownSubcommandError();
        $failed += $this->testNotYetImplementedSubcommand();
        $failed += $this->testMissingLockfileError();

        return $failed;
    }

    private function testHelpDisplay(): int
    {
        [$output, $exit] = $this->run_(['help']);

        if ($exit !== 0) {
            echo "FAIL testHelpDisplay: expected exit 0, got {$exit}\n";
            return 1;
        }

        if (!str_contains($output, 'install')) {
            echo "FAIL testHelpDisplay: expected 'install' in output\n";
            return 1;
        }

        echo "OK testHelpDisplay\n";
        return 0;
    }

    private function testInstallCommandHelp(): int
    {
        [$output, $exit] = $this->run_(['help', 'install']);

        if ($exit !== 0) {
            echo "FAIL testInstallCommandHelp: expected exit 0, got {$exit}\n";
            return 1;
        }

        if (!str_contains($output, '--preview-only')) {
            echo "FAIL testInstallCommandHelp: expected '--preview-only' in output\n";
            return 1;
        }

        if (!str_contains($output, '--dry-run')) {
            echo "FAIL testInstallCommandHelp: expected '--dry-run' in output\n";
            return 1;
        }

        echo "OK testInstallCommandHelp\n";
        return 0;
    }

    private function testInstallPreviewOnlyWithEmptyLockfile(): int
    {
        [$output, $exit] = $this->run_(['install', '--preview-only']);

        if ($exit !== 0) {
            echo "FAIL testInstallPreviewOnlyWithEmptyLockfile: expected exit 0, got {$exit}\nOutput: {$output}\n";
            return 1;
        }

        if (!str_contains($output, 'Installation plan')) {
            echo "FAIL testInstallPreviewOnlyWithEmptyLockfile: expected 'Installation plan' in output\n";
            return 1;
        }

        echo "OK testInstallPreviewOnlyWithEmptyLockfile\n";
        return 0;
    }

    private function testInstallDryRunWithEmptyLockfile(): int
    {
        [$output, $exit] = $this->run_(['install', '--dry-run']);

        if ($exit !== 0) {
            echo "FAIL testInstallDryRunWithEmptyLockfile: expected exit 0, got {$exit}\nOutput: {$output}\n";
            return 1;
        }

        if (!str_contains($output, 'dry-run')) {
            echo "FAIL testInstallDryRunWithEmptyLockfile: expected 'dry-run' in output\n";
            return 1;
        }

        echo "OK testInstallDryRunWithEmptyLockfile\n";
        return 0;
    }

    private function testMutualExclusionFlags(): int
    {
        [$output, $exit] = $this->run_(['install', '--preview-only', '--dry-run']);

        if ($exit === 0) {
            echo "FAIL testMutualExclusionFlags: expected non-zero exit\n";
            return 1;
        }

        if (!str_contains($output, 'mutually exclusive')) {
            echo "FAIL testMutualExclusionFlags: expected 'mutually exclusive' in output\n";
            return 1;
        }

        echo "OK testMutualExclusionFlags\n";
        return 0;
    }

    private function testUnknownSubcommandError(): int
    {
        [$output, $exit] = $this->run_(['nonexistent-cmd']);

        if ($exit === 0) {
            echo "FAIL testUnknownSubcommandError: expected non-zero exit\n";
            return 1;
        }

        if (!str_contains($output, 'Unknown subcommand')) {
            echo "FAIL testUnknownSubcommandError: expected 'Unknown subcommand' in output\n";
            return 1;
        }

        echo "OK testUnknownSubcommandError\n";
        return 0;
    }

    private function testNotYetImplementedSubcommand(): int
    {
        [$output, $exit] = $this->run_(['update']);

        if ($exit === 0) {
            echo "FAIL testNotYetImplementedSubcommand: expected non-zero exit\n";
            return 1;
        }

        if (!str_contains($output, 'not yet implemented')) {
            echo "FAIL testNotYetImplementedSubcommand: expected 'not yet implemented' in output\n";
            return 1;
        }

        echo "OK testNotYetImplementedSubcommand\n";
        return 0;
    }

    private function testMissingLockfileError(): int
    {
        // Point setup.php to a temp directory that has no lockfile via the
        // SOMANAGER_PROJECT_ROOT env var, which SetupRunner reads to override
        // the lockfile and manifest paths at runtime.
        $tmpDir = sys_get_temp_dir() . '/setup_test_no_lock_' . uniqid();
        mkdir($tmpDir, 0o755, true);

        try {
            [$output, $exit] = $this->run_(
                ['install', '--preview-only'],
                ['SOMANAGER_PROJECT_ROOT' => $tmpDir],
            );

            if ($exit === 0) {
                echo "FAIL testMissingLockfileError: expected non-zero exit\nOutput: {$output}\n";
                return 1;
            }

            if (!str_contains($output, 'Lockfile absent')) {
                echo "FAIL testMissingLockfileError: expected 'Lockfile absent' in output\nOutput: {$output}\n";
                return 1;
            }
        } finally {
            @rmdir($tmpDir);
        }

        echo "OK testMissingLockfileError\n";
        return 0;
    }

    /**
     * Runs setup.php with the given arguments and returns [stdout+stderr, exit_code].
     *
     * @param list<string>         $args CLI arguments
     * @param array<string,string> $env  Optional env vars prepended to the command
     * @return array{0: string, 1: int}
     */
    private function run_(array $args, array $env = []): array
    {
        $envPrefix = '';
        if ($env !== []) {
            $parts = [];
            foreach ($env as $key => $value) {
                $parts[] = sprintf('%s=%s', $key, escapeshellarg($value));
            }
            $envPrefix = implode(' ', $parts) . ' ';
        }

        $cmd = sprintf(
            '%sphp %s %s 2>&1',
            $envPrefix,
            escapeshellarg($this->projectRoot . '/scripts/setup.php'),
            implode(' ', array_map('escapeshellarg', $args)),
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes, $this->projectRoot);
        if (!is_resource($process)) {
            return ['Failed to open process', 1];
        }

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit = proc_close($process);

        return [$output, $exit];
    }
}
