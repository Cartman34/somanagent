<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Server\Test;

/**
 * Integration tests for scripts/server.php.
 *
 * Runs the real script as a subprocess. Tests are limited to paths that do not
 * require Docker: help display, preview-only/dry-run modes, and error cases.
 */
final class ServerRunnerTest
{
    private const SCRIPT = 'scripts/server.php';

    /**
     * Runs all test cases and returns the total number of failures.
     */
    public function run(): int
    {
        $failed = 0;

        $failed += $this->testHelpDisplays();
        $failed += $this->testSubcommandHelpDisplays();
        $failed += $this->testUnknownSubcommandFails();
        $failed += $this->testPreviewOnlyShowsPlanWithoutDocker();
        $failed += $this->testDryRunShowsPlanWithoutDocker();
        $failed += $this->testMutuallyExclusiveFlagsRejected();
        $failed += $this->testHelpFlagAcceptedOnMutationCommand();
        $failed += $this->testRestartPreviewOnly();

        return $failed;
    }

    private function testHelpDisplays(): int
    {
        [$exit, $stdout] = $this->runScript(['help']);
        if ($exit !== 0) {
            echo "FAIL testHelpDisplays: expected exit 0, got {$exit}\n";

            return 1;
        }
        if (!str_contains($stdout, 'start') || !str_contains($stdout, 'stop') || !str_contains($stdout, 'health')) {
            echo "FAIL testHelpDisplays: expected start/stop/health in output: {$stdout}\n";

            return 1;
        }
        echo "OK testHelpDisplays\n";

        return 0;
    }

    private function testSubcommandHelpDisplays(): int
    {
        [$exit, $stdout] = $this->runScript(['start', '--help']);
        if ($exit !== 0) {
            echo "FAIL testSubcommandHelpDisplays: expected exit 0, got {$exit}\n";

            return 1;
        }
        if (!str_contains($stdout, '--minimal') || !str_contains($stdout, '--preview-only')) {
            echo "FAIL testSubcommandHelpDisplays: expected --minimal and --preview-only in output: {$stdout}\n";

            return 1;
        }
        echo "OK testSubcommandHelpDisplays\n";

        return 0;
    }

    private function testUnknownSubcommandFails(): int
    {
        [$exit, $stdout, $stderr] = $this->runScript(['bogus-command']);
        if ($exit === 0) {
            echo "FAIL testUnknownSubcommandFails: expected non-zero exit\n";

            return 1;
        }
        $output = $stdout . "\n" . $stderr;
        if (!str_contains($output, "Unknown subcommand: 'bogus-command'")) {
            echo "FAIL testUnknownSubcommandFails: unexpected output: {$output}\n";

            return 1;
        }
        echo "OK testUnknownSubcommandFails\n";

        return 0;
    }

    private function testPreviewOnlyShowsPlanWithoutDocker(): int
    {
        [$exit, $stdout] = $this->runScript(['start', '--preview-only']);
        if ($exit !== 0) {
            echo "FAIL testPreviewOnlyShowsPlanWithoutDocker: expected exit 0, got {$exit}\nOutput: {$stdout}\n";

            return 1;
        }
        if (!str_contains($stdout, 'Preview:') || !str_contains($stdout, 'docker compose')) {
            echo "FAIL testPreviewOnlyShowsPlanWithoutDocker: expected Preview block in output: {$stdout}\n";

            return 1;
        }
        echo "OK testPreviewOnlyShowsPlanWithoutDocker\n";

        return 0;
    }

    private function testDryRunShowsPlanWithoutDocker(): int
    {
        [$exit, $stdout] = $this->runScript(['start', '--dry-run']);
        if ($exit !== 0) {
            echo "FAIL testDryRunShowsPlanWithoutDocker: expected exit 0, got {$exit}\nOutput: {$stdout}\n";

            return 1;
        }
        if (!str_contains($stdout, 'dry-run') || !str_contains($stdout, 'Preview:')) {
            echo "FAIL testDryRunShowsPlanWithoutDocker: expected dry-run marker and Preview: in output: {$stdout}\n";

            return 1;
        }
        echo "OK testDryRunShowsPlanWithoutDocker\n";

        return 0;
    }

    private function testMutuallyExclusiveFlagsRejected(): int
    {
        [$exit, $stdout, $stderr] = $this->runScript(['start', '--preview-only', '--dry-run']);
        if ($exit === 0) {
            echo "FAIL testMutuallyExclusiveFlagsRejected: expected non-zero exit\n";

            return 1;
        }
        $output = $stdout . "\n" . $stderr;
        if (!str_contains($output, 'mutually exclusive')) {
            echo "FAIL testMutuallyExclusiveFlagsRejected: expected 'mutually exclusive' in output: {$output}\n";

            return 1;
        }
        echo "OK testMutuallyExclusiveFlagsRejected\n";

        return 0;
    }

    private function testHelpFlagAcceptedOnMutationCommand(): int
    {
        [$exit, $stdout] = $this->runScript(['stop', '--help']);
        if ($exit !== 0) {
            echo "FAIL testHelpFlagAcceptedOnMutationCommand: expected exit 0, got {$exit}\n";

            return 1;
        }
        if (!str_contains($stdout, '--force') || !str_contains($stdout, '--preview-only')) {
            echo "FAIL testHelpFlagAcceptedOnMutationCommand: expected --force and --preview-only in stop help: {$stdout}\n";

            return 1;
        }
        echo "OK testHelpFlagAcceptedOnMutationCommand\n";

        return 0;
    }

    private function testRestartPreviewOnly(): int
    {
        [$exit, $stdout] = $this->runScript(['restart', '--preview-only']);
        if ($exit !== 0) {
            echo "FAIL testRestartPreviewOnly: expected exit 0, got {$exit}\nOutput: {$stdout}\n";

            return 1;
        }
        if (!str_contains($stdout, 'docker compose down') || !str_contains($stdout, 'docker compose')) {
            echo "FAIL testRestartPreviewOnly: expected stop and start commands in restart preview: {$stdout}\n";

            return 1;
        }
        echo "OK testRestartPreviewOnly\n";

        return 0;
    }

    /**
     * @param list<string> $args
     * @return array{0: int, 1: string, 2: string} [exitCode, stdout, stderr]
     */
    private function runScript(array $args): array
    {
        $projectRoot = dirname(__DIR__, 4);
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($projectRoot . '/' . self::SCRIPT);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }

        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($cmd, $descriptors, $pipes, $projectRoot);
        if (!is_resource($process)) {
            return [-1, '', 'failed to start subprocess'];
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return [$exit, $stdout, $stderr];
    }
}
