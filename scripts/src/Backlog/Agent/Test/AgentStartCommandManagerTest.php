<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Agent\Test;

/**
 * Tests for manager-mode behaviour in the start command.
 *
 * Uses subprocess execution so the full runner, option validator and
 * command wiring are exercised without manual dependency injection.
 */
final class AgentStartCommandManagerTest
{
    private const SCRIPT = 'scripts/backlog-agent.php';

    /**
     * Runs all test cases and returns the total number of failures.
     */
    public function run(): int
    {
        $failed = 0;

        $failed += $this->testRejectsResetWithManager();
        $failed += $this->testManagerDoesNotCreateWorktree();

        return $failed;
    }

    private function testRejectsResetWithManager(): int
    {
        [$exit, $stdout, $stderr] = $this->runScript(['start', 'claude', '--manager', '--reset']);

        if ($exit === 0) {
            echo "FAIL testRejectsResetWithManager: expected non-zero exit\n";
            return 1;
        }

        $output = $stderr . "\n" . $stdout;
        if (!str_contains($output, '--reset is only allowed with --developer')) {
            echo "FAIL testRejectsResetWithManager: unexpected output: {$output}\n";
            return 1;
        }

        echo "OK testRejectsResetWithManager\n";
        return 0;
    }

    private function testManagerDoesNotCreateWorktree(): int
    {
        $projectRoot = dirname(__DIR__, 5);
        $worktreesRoot = $projectRoot . '/.agent-worktrees';
        $code = 'm97';
        $worktreePath = $worktreesRoot . '/' . $code;

        if (is_dir($worktreePath)) {
            echo "SKIP testManagerDoesNotCreateWorktree: worktree {$code} already exists\n";
            return 0;
        }

        // Run start --manager --code=m97; the command will fail (client unavailable or session
        // blocked) but must not create .agent-worktrees/m97.
        $this->runScript(['start', 'claude', '--manager', '--code=' . $code]);

        if (is_dir($worktreePath)) {
            echo "FAIL testManagerDoesNotCreateWorktree: worktree {$worktreePath} was created for manager mode\n";
            rmdir($worktreePath);
            return 1;
        }

        echo "OK testManagerDoesNotCreateWorktree\n";
        return 0;
    }

    /**
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
