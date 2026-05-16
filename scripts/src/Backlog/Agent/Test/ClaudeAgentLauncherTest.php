<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Test;

use SoManAgent\Script\Backlog\Agent\Client\ClaudeAgentLauncher;
use SoManAgent\Script\Backlog\Agent\Client\ProcessRunner;
use SoManAgent\Script\Backlog\Agent\Enum\AgentClient;
use SoManAgent\Script\Backlog\Agent\Enum\AgentRole;

/**
 * Unit tests for ClaudeAgentLauncher hooks.
 */
final class ClaudeAgentLauncherTest
{
    private string $tmpDir;

    /**
     * Creates a temporary test root used for context and Claude session fixtures.
     */
    public function __construct()
    {
        $this->tmpDir = sys_get_temp_dir() . '/backlog-agent-claude-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
    }

    /**
     * Removes the temporary test root.
     */
    public function __destruct()
    {
        $this->rmdir($this->tmpDir);
    }

    /**
     * Runs all test cases and returns the total number of failures.
     */
    public function run(): int
    {
        $failed = 0;

        $failed += $this->testClient();
        $failed += $this->testIsAvailableUsesProcessRunner();
        $failed += $this->testBuildLaunchCommandInitial();
        $failed += $this->testBuildLaunchCommandFailsWhenContextIsMissing();
        $failed += $this->testBuildLaunchCommandContinue();
        $failed += $this->testBuildLaunchCommandResumeId();
        $failed += $this->testCaptureCurrentSessionId();
        $failed += $this->testListSessionsParsesClaudeJsonl();

        return $failed;
    }

    private function testClient(): int
    {
        $launcher = new ClaudeAgentLauncher($this->makeProcessRunner(true), $this->tmpDir);

        if ($launcher->client() !== AgentClient::CLAUDE) {
            echo "FAIL testClient: expected CLAUDE\n";
            return 1;
        }

        echo "OK testClient\n";
        return 0;
    }

    private function testIsAvailableUsesProcessRunner(): int
    {
        $runner = $this->makeProcessRunner(false);
        $launcher = new ClaudeAgentLauncher($runner, $this->tmpDir);

        if ($launcher->isAvailable()) {
            echo "FAIL testIsAvailableUsesProcessRunner: expected unavailable\n";
            return 1;
        }

        echo "OK testIsAvailableUsesProcessRunner\n";
        return 0;
    }

    private function testBuildLaunchCommandInitial(): int
    {
        $context = $this->writeContext('context initial');
        $launcher = new ClaudeAgentLauncher($this->makeProcessRunner(true), $this->tmpDir);

        [$bin, $args] = $launcher->buildLaunchCommand('/worktree', $context, AgentRole::DEVELOPER);

        // Strict equality enforces the absence of --cwd / /worktree (claude v2.x rejects --cwd).
        $expected = ['--append-system-prompt', 'context initial'];
        if ($bin !== 'claude' || $args !== $expected) {
            echo "FAIL testBuildLaunchCommandInitial: unexpected command\n";
            return 1;
        }

        echo "OK testBuildLaunchCommandInitial\n";
        return 0;
    }

    private function testBuildLaunchCommandFailsWhenContextIsMissing(): int
    {
        $launcher = new ClaudeAgentLauncher($this->makeProcessRunner(true), $this->tmpDir);

        try {
            $launcher->buildLaunchCommand('/worktree', $this->tmpDir . '/missing-context.md', AgentRole::DEVELOPER);
            echo "FAIL testBuildLaunchCommandFailsWhenContextIsMissing: expected RuntimeException\n";
            return 1;
        } catch (\RuntimeException $e) {
            echo "OK testBuildLaunchCommandFailsWhenContextIsMissing\n";
            return 0;
        }
    }

    private function testBuildLaunchCommandContinue(): int
    {
        $context = $this->writeContext('context continue');
        $launcher = new ClaudeAgentLauncher($this->makeProcessRunner(true), $this->tmpDir);

        [, $args] = $launcher->buildLaunchCommand('/worktree', $context, AgentRole::DEVELOPER, null, true);

        if (!in_array('--continue', $args, true)) {
            echo "FAIL testBuildLaunchCommandContinue: missing --continue\n";
            return 1;
        }
        if (in_array('--cwd', $args, true) || in_array('/worktree', $args, true)) {
            echo "FAIL testBuildLaunchCommandContinue: --cwd must not be passed in continue mode either (claude v2.x rejects it)\n";
            return 1;
        }

        echo "OK testBuildLaunchCommandContinue\n";
        return 0;
    }

    private function testBuildLaunchCommandResumeId(): int
    {
        $context = $this->writeContext('context resume');
        $launcher = new ClaudeAgentLauncher($this->makeProcessRunner(true), $this->tmpDir);

        [, $args] = $launcher->buildLaunchCommand('/worktree', $context, AgentRole::DEVELOPER, 'session-123');

        if (!in_array('--resume', $args, true) || !in_array('session-123', $args, true)) {
            echo "FAIL testBuildLaunchCommandResumeId: missing resume args\n";
            return 1;
        }
        if (in_array('--continue', $args, true)) {
            echo "FAIL testBuildLaunchCommandResumeId: resume id must not add --continue\n";
            return 1;
        }
        if (in_array('--cwd', $args, true) || in_array('/worktree', $args, true)) {
            echo "FAIL testBuildLaunchCommandResumeId: --cwd must not be passed in resume mode either (claude v2.x rejects it)\n";
            return 1;
        }

        echo "OK testBuildLaunchCommandResumeId\n";
        return 0;
    }

    private function testCaptureCurrentSessionId(): int
    {
        $worktree = $this->makeWorktree('capture');
        $dir = $this->makeClaudeProjectDir($worktree);
        $old = $dir . '/old-session.jsonl';
        $new = $dir . '/new-session.jsonl';
        file_put_contents($old, "{}\n");
        file_put_contents($new, "{}\n");
        touch($old, 1000);
        touch($new, 2000);

        $launcher = new ClaudeAgentLauncher($this->makeProcessRunner(true), $this->tmpDir);

        if ($launcher->captureCurrentSessionId($worktree) !== 'new-session') {
            echo "FAIL testCaptureCurrentSessionId: expected newest filename id\n";
            return 1;
        }

        echo "OK testCaptureCurrentSessionId\n";
        return 0;
    }

    private function testListSessionsParsesClaudeJsonl(): int
    {
        $worktree = $this->makeWorktree('sessions');
        $dir = $this->makeClaudeProjectDir($worktree);
        file_put_contents($dir . '/session-a.jsonl', implode("\n", [
            json_encode(['type' => 'summary', 'sessionId' => 'session-a']),
            json_encode(['timestamp' => '2026-01-01T10:00:00+00:00', 'type' => 'user', 'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => str_repeat('A', 90)]]]]),
            json_encode(['timestamp' => '2026-01-01T10:05:00+00:00', 'type' => 'assistant', 'message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'answer']]]]),
            '',
        ]));
        file_put_contents($dir . '/session-b.jsonl', implode("\n", [
            json_encode(['timestamp' => '2026-01-01T11:00:00+00:00', 'type' => 'user', 'message' => ['role' => 'user', 'content' => 'second prompt']]),
            '',
        ]));

        $launcher = new ClaudeAgentLauncher($this->makeProcessRunner(true), $this->tmpDir);
        $sessions = $launcher->listSessions($worktree);

        if (count($sessions) !== 2 || $sessions[0]->id !== 'session-b' || $sessions[1]->id !== 'session-a') {
            echo "FAIL testListSessionsParsesClaudeJsonl: unexpected order or count\n";
            return 1;
        }
        if ($sessions[1]->messageCount !== 2 || $sessions[1]->startedAt?->format(DATE_ATOM) !== '2026-01-01T10:00:00+00:00') {
            echo "FAIL testListSessionsParsesClaudeJsonl: unexpected metadata\n";
            return 1;
        }
        if ($sessions[1]->firstPromptExcerpt !== str_repeat('A', 80)) {
            echo "FAIL testListSessionsParsesClaudeJsonl: prompt excerpt not truncated\n";
            return 1;
        }

        echo "OK testListSessionsParsesClaudeJsonl\n";
        return 0;
    }

    private function writeContext(string $content): string
    {
        $path = $this->tmpDir . '/context-' . uniqid('', true) . '.md';
        file_put_contents($path, $content);

        return $path;
    }

    private function makeWorktree(string $name): string
    {
        $path = $this->tmpDir . '/worktrees/' . $name;
        mkdir($path, 0755, true);

        return $path;
    }

    private function makeClaudeProjectDir(string $worktree): string
    {
        $encoded = str_replace('/', '-', str_replace('\\', '/', realpath($worktree) ?: $worktree));
        $dir = $this->tmpDir . '/.claude/projects/' . $encoded;
        mkdir($dir, 0755, true);

        return $dir;
    }

    private function makeProcessRunner(bool $succeeds): ProcessRunner
    {
        return new class($succeeds) implements ProcessRunner {
            /**
             * @param bool $result Availability result returned by succeeds()
             */
            public function __construct(private bool $result) {}

            /**
             * Returns the configured availability result.
             */
            public function succeeds(string $command): bool
            {
                return $this->result;
            }

            /**
             * Not used by ClaudeAgentLauncher.
             */
            public function output(string $command, string $cwd = ''): ?string
            {
                return null;
            }
        };
    }

    private function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
