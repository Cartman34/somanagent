<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Agent\Test;

/**
 * Integration tests for strict option parsing in scripts/backlog-agent.php.
 *
 * Runs the real script as a subprocess so the runner, parser and validator are
 * exercised together. Subcommands selected for the smoke checks are pure
 * argument-validation paths (status, list) that do not require live sessions.
 */
final class BacklogAgentRunnerStrictOptionsTest
{
    private const SCRIPT = 'scripts/backlog-agent.php';

    /**
     * Runs all test cases and returns the total number of failures.
     */
    public function run(): int
    {
        $failed = 0;

        $failed += $this->testRejectsUnknownEqualsForm();
        $failed += $this->testRejectsUnknownSpaceForm();
        $failed += $this->testRejectsAsTypoOnStatus();
        $failed += $this->testAcceptsKnownEqualsForm();
        $failed += $this->testAcceptsKnownSpaceForm();
        $failed += $this->testHelpFlagIsAlwaysAccepted();

        return $failed;
    }

    private function testRejectsUnknownEqualsForm(): int
    {
        [$exit, $stdout, $stderr] = $this->runScript(['status', '--unknown-flag=value']);
        if ($exit === 0) {
            echo "FAIL testRejectsUnknownEqualsForm: expected non-zero exit\n";
            return 1;
        }
        $output = $stderr . "\n" . $stdout;
        if (!str_contains($output, 'Unknown option(s) for command `status`: --unknown-flag')) {
            echo "FAIL testRejectsUnknownEqualsForm: unexpected output: {$output}\n";
            return 1;
        }
        echo "OK testRejectsUnknownEqualsForm\n";
        return 0;
    }

    private function testRejectsUnknownSpaceForm(): int
    {
        [$exit, $stdout, $stderr] = $this->runScript(['status', '--unknown-flag', 'value']);
        if ($exit === 0) {
            echo "FAIL testRejectsUnknownSpaceForm: expected non-zero exit\n";
            return 1;
        }
        $output = $stderr . "\n" . $stdout;
        if (!str_contains($output, 'Unknown option(s) for command `status`: --unknown-flag')) {
            echo "FAIL testRejectsUnknownSpaceForm: unexpected output: {$output}\n";
            return 1;
        }
        echo "OK testRejectsUnknownSpaceForm\n";
        return 0;
    }

    private function testRejectsAsTypoOnStatus(): int
    {
        [$exit, $stdout, $stderr] = $this->runScript(['status', '--as=d04']);
        if ($exit === 0) {
            echo "FAIL testRejectsAsTypoOnStatus: expected non-zero exit\n";
            return 1;
        }
        $output = $stderr . "\n" . $stdout;
        if (!str_contains($output, 'Unknown option(s) for command `status`: --as')) {
            echo "FAIL testRejectsAsTypoOnStatus: unexpected output: {$output}\n";
            return 1;
        }
        echo "OK testRejectsAsTypoOnStatus\n";
        return 0;
    }

    private function testAcceptsKnownEqualsForm(): int
    {
        [$exit, $stdout, $stderr] = $this->runScript(['status', '--code=d99', '--help']);
        // --help is always accepted, --code is declared on status. No unknown error expected.
        $output = $stderr . "\n" . $stdout;
        if (str_contains($output, 'Unknown option(s)')) {
            echo "FAIL testAcceptsKnownEqualsForm: rejected known options: {$output}\n";
            return 1;
        }
        if ($exit !== 0) {
            echo "FAIL testAcceptsKnownEqualsForm: expected exit 0, got {$exit}: {$output}\n";
            return 1;
        }
        echo "OK testAcceptsKnownEqualsForm\n";
        return 0;
    }

    private function testAcceptsKnownSpaceForm(): int
    {
        [$exit, $stdout, $stderr] = $this->runScript(['status', '--code', 'd99', '--help']);
        $output = $stderr . "\n" . $stdout;
        if (str_contains($output, 'Unknown option(s)')) {
            echo "FAIL testAcceptsKnownSpaceForm: rejected known options: {$output}\n";
            return 1;
        }
        if ($exit !== 0) {
            echo "FAIL testAcceptsKnownSpaceForm: expected exit 0, got {$exit}: {$output}\n";
            return 1;
        }
        echo "OK testAcceptsKnownSpaceForm\n";
        return 0;
    }

    private function testHelpFlagIsAlwaysAccepted(): int
    {
        [$exit, $stdout, $stderr] = $this->runScript(['list', '--help']);
        $output = $stderr . "\n" . $stdout;
        if (str_contains($output, 'Unknown option(s)')) {
            echo "FAIL testHelpFlagIsAlwaysAccepted: --help rejected: {$output}\n";
            return 1;
        }
        if ($exit !== 0) {
            echo "FAIL testHelpFlagIsAlwaysAccepted: expected exit 0, got {$exit}\n";
            return 1;
        }
        echo "OK testHelpFlagIsAlwaysAccepted\n";
        return 0;
    }

    /**
     * Runs scripts/backlog-agent.php with the given args from the project root.
     *
     * Always passes --force-current-worktree so the proxy stays in the current
     * worktree even when the script does not yet exist in WP (typical during
     * the integration of the parent feature). Strict-option parsing must still
     * apply in this mode because the proxy bypass flag is itself an explicit
     * runner-level option.
     *
     * @param list<string> $args
     * @return array{0: int, 1: string, 2: string} [exitCode, stdout, stderr]
     */
    private function runScript(array $args): array
    {
        $projectRoot = dirname(__DIR__, 5);
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($projectRoot . '/' . self::SCRIPT)
            . ' --force-current-worktree';
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
