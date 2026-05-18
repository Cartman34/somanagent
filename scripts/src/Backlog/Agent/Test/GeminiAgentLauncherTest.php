<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Test;

use SoManAgent\Script\Backlog\Agent\Client\GeminiAgentLauncher;
use SoManAgent\Script\Backlog\Agent\Client\ProcessRunner;
use SoManAgent\Script\Backlog\Agent\Enum\AgentClient;
use SoManAgent\Script\Backlog\Agent\Enum\AgentRole;

/**
 * Unit tests for GeminiAgentLauncher hooks.
 */
final class GeminiAgentLauncherTest
{
    private string $tmpDir;

    /**
     * Creates a temporary test root used for context and session fixtures.
     */
    public function __construct()
    {
        $this->tmpDir = sys_get_temp_dir() . '/backlog-agent-gemini-test-' . uniqid('', true);
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
     * @api
     */
    public function run(): int
    {
        $failed = 0;

        $failed += $this->testClient();
        $failed += $this->testIsAvailableUsesProcessRunner();
        $failed += $this->testBuildEnvironmentAddsGeminiSystemMd();
        $failed += $this->testBuildLaunchCommandInitial();
        $failed += $this->testBuildLaunchCommandInitialPrompt();
        $failed += $this->testBuildLaunchCommandContinue();
        $failed += $this->testBuildLaunchCommandResumeId();
        $failed += $this->testBuildLaunchCommandIncludesPermissionFlags();
        $failed += $this->testBuildLaunchCommandIncludesBacklogDir();
        $failed += $this->testListSessionsFromGeminiTableOutput();
        $failed += $this->testListSessionsFromJsonOutput();
        $failed += $this->testListSessionsFallsBackToDirectory();
        $failed += $this->testListSessionsReturnsEmptyWhenGeminiSucceedsWithNoSessions();
        $failed += $this->testCaptureCurrentSessionIdReturnsFirstFromList();

        return $failed;
    }

    private function testClient(): int
    {
        $launcher = new GeminiAgentLauncher($this->makeProcessRunner(true), $this->tmpDir);

        if ($launcher->client() !== AgentClient::GEMINI) {
            echo "FAIL testClient: expected GEMINI\n";
            return 1;
        }

        echo "OK testClient\n";
        return 0;
    }

    private function testIsAvailableUsesProcessRunner(): int
    {
        $launcher = new GeminiAgentLauncher($this->makeProcessRunner(false), $this->tmpDir);

        if ($launcher->isAvailable()) {
            echo "FAIL testIsAvailableUsesProcessRunner: expected unavailable\n";
            return 1;
        }

        echo "OK testIsAvailableUsesProcessRunner\n";
        return 0;
    }

    private function testBuildEnvironmentAddsGeminiSystemMd(): int
    {
        $context = $this->writeContext('gemini context');
        $launcher = new GeminiAgentLauncher($this->makeProcessRunner(true), $this->tmpDir);

        $env = $launcher->buildEnvironment(['FOO' => 'bar'], $context);

        if (!isset($env['GEMINI_SYSTEM_MD'])) {
            echo "FAIL testBuildEnvironmentAddsGeminiSystemMd: GEMINI_SYSTEM_MD missing\n";
            return 1;
        }
        if ($env['FOO'] !== 'bar') {
            echo "FAIL testBuildEnvironmentAddsGeminiSystemMd: base env key lost\n";
            return 1;
        }
        if ($env['GEMINI_SYSTEM_MD'] !== (realpath($context) ?: $context)) {
            echo "FAIL testBuildEnvironmentAddsGeminiSystemMd: unexpected GEMINI_SYSTEM_MD value\n";
            return 1;
        }

        echo "OK testBuildEnvironmentAddsGeminiSystemMd\n";
        return 0;
    }

    private function testBuildLaunchCommandInitial(): int
    {
        $launcher = new GeminiAgentLauncher($this->makeProcessRunner(true), $this->tmpDir);

        [$bin, $args] = $launcher->buildLaunchCommand('/worktree', '/ctx.md', AgentRole::DEVELOPER);

        if ($bin !== 'gemini' || $args !== ['--approval-mode', 'auto_edit', '--skip-trust']) {
            echo "FAIL testBuildLaunchCommandInitial: unexpected command\n";
            return 1;
        }

        echo "OK testBuildLaunchCommandInitial\n";
        return 0;
    }

    private function testBuildLaunchCommandInitialPrompt(): int
    {
        $launcher = new GeminiAgentLauncher($this->makeProcessRunner(true), $this->tmpDir);

        [$bin, $args] = $launcher->buildLaunchCommand(
            '/worktree',
            '/ctx.md',
            AgentRole::DEVELOPER,
            null,
            false,
            null,
            'initial user prompt',
        );

        if ($bin !== 'gemini' || $args !== ['--approval-mode', 'auto_edit', '--skip-trust', '--prompt-interactive', 'initial user prompt']) {
            echo "FAIL testBuildLaunchCommandInitialPrompt: unexpected command\n";
            return 1;
        }

        echo "OK testBuildLaunchCommandInitialPrompt\n";
        return 0;
    }

    private function testBuildLaunchCommandContinue(): int
    {
        $launcher = new GeminiAgentLauncher($this->makeProcessRunner(true), $this->tmpDir);

        [$bin, $args] = $launcher->buildLaunchCommand('/worktree', '/ctx.md', AgentRole::DEVELOPER, null, true);

        if ($bin !== 'gemini' || $args !== ['--approval-mode', 'auto_edit', '--skip-trust', '-r', 'latest']) {
            echo "FAIL testBuildLaunchCommandContinue: expected permission flags + ['-r', 'latest']\n";
            return 1;
        }

        echo "OK testBuildLaunchCommandContinue\n";
        return 0;
    }

    private function testBuildLaunchCommandResumeId(): int
    {
        $launcher = new GeminiAgentLauncher($this->makeProcessRunner(true), $this->tmpDir);

        [$bin, $args] = $launcher->buildLaunchCommand('/worktree', '/ctx.md', AgentRole::DEVELOPER, 'session-abc');

        if ($bin !== 'gemini' || $args !== ['--approval-mode', 'auto_edit', '--skip-trust', '-r', 'session-abc']) {
            echo "FAIL testBuildLaunchCommandResumeId: expected permission flags + ['-r', 'session-abc']\n";
            return 1;
        }

        echo "OK testBuildLaunchCommandResumeId\n";
        return 0;
    }

    private function testBuildLaunchCommandIncludesPermissionFlags(): int
    {
        $launcher = new GeminiAgentLauncher($this->makeProcessRunner(true), $this->tmpDir);

        foreach ([
            'initial' => $launcher->buildLaunchCommand('/worktree', '/ctx.md', AgentRole::DEVELOPER),
            'continue' => $launcher->buildLaunchCommand('/worktree', '/ctx.md', AgentRole::DEVELOPER, null, true),
            'resume' => $launcher->buildLaunchCommand('/worktree', '/ctx.md', AgentRole::DEVELOPER, 'session-x'),
        ] as $mode => [, $args]) {
            if (!in_array('--approval-mode', $args, true) || !in_array('auto_edit', $args, true) || !in_array('--skip-trust', $args, true)) {
                echo "FAIL testBuildLaunchCommandIncludesPermissionFlags ($mode): --approval-mode auto_edit --skip-trust missing\n";
                return 1;
            }
        }

        echo "OK testBuildLaunchCommandIncludesPermissionFlags\n";
        return 0;
    }

    private function testBuildLaunchCommandIncludesBacklogDir(): int
    {
        $launcher = new GeminiAgentLauncher($this->makeProcessRunner(true), $this->tmpDir, '/wp-root');

        foreach ([
            'initial' => $launcher->buildLaunchCommand('/worktree', '/ctx.md', AgentRole::DEVELOPER),
            'continue' => $launcher->buildLaunchCommand('/worktree', '/ctx.md', AgentRole::DEVELOPER, null, true),
            'resume' => $launcher->buildLaunchCommand('/worktree', '/ctx.md', AgentRole::DEVELOPER, 'session-x'),
        ] as $mode => [, $args]) {
            $idx = array_search('--include-directories', $args, true);
            if ($idx === false || ($args[$idx + 1] ?? null) !== '/wp-root/local/backlog') {
                echo "FAIL testBuildLaunchCommandIncludesBacklogDir ({$mode}): --include-directories /wp-root/local/backlog missing\n";
                return 1;
            }
        }

        echo "OK testBuildLaunchCommandIncludesBacklogDir\n";
        return 0;
    }

    private function testListSessionsFromGeminiTableOutput(): int
    {
        $tableOutput = implode("\n", [
            'Session ID    Created At',
            'abc123def     2026-05-02T09:00:00Z',
            'xyz789ghi     2026-05-01T10:00:00Z',
        ]);
        $launcher = new GeminiAgentLauncher($this->makeProcessRunner(true, $tableOutput), $this->tmpDir);

        $sessions = $launcher->listSessions('/fake-worktree');

        if (count($sessions) !== 2 || $sessions[0]->id !== 'abc123def' || $sessions[1]->id !== 'xyz789ghi') {
            echo "FAIL testListSessionsFromGeminiTableOutput: unexpected sessions\n";
            return 1;
        }

        echo "OK testListSessionsFromGeminiTableOutput\n";
        return 0;
    }

    private function testListSessionsFromJsonOutput(): int
    {
        $jsonOutput = json_encode([
            ['id' => 'sess-1', 'created_at' => '2026-05-02T09:00:00+00:00', 'message_count' => 4],
            ['id' => 'sess-2', 'created_at' => '2026-05-01T08:00:00+00:00', 'message_count' => 2],
        ]);
        assert(is_string($jsonOutput));
        $launcher = new GeminiAgentLauncher($this->makeProcessRunner(true, $jsonOutput), $this->tmpDir);

        $sessions = $launcher->listSessions('/fake-worktree');

        if (count($sessions) !== 2 || $sessions[0]->id !== 'sess-1' || $sessions[0]->messageCount !== 4) {
            echo "FAIL testListSessionsFromJsonOutput: unexpected sessions\n";
            return 1;
        }
        if ($sessions[0]->startedAt?->format('Y-m-d') !== '2026-05-02') {
            echo "FAIL testListSessionsFromJsonOutput: unexpected startedAt\n";
            return 1;
        }

        echo "OK testListSessionsFromJsonOutput\n";
        return 0;
    }

    private function testListSessionsFallsBackToDirectory(): int
    {
        // ProcessRunner returns null (gemini --list-sessions fails)
        $launcher = new GeminiAgentLauncher($this->makeProcessRunner(true, null), $this->tmpDir);

        $worktree = $this->makeWorktree('fallback');
        $projectHash = hash('sha256', realpath($worktree) ?: $worktree);
        $sessionDir = $this->tmpDir . '/.gemini/tmp/' . $projectHash;
        mkdir($sessionDir, 0755, true);

        file_put_contents($sessionDir . '/older-session.json', json_encode([
            'created_at' => '2026-04-01T10:00:00+00:00',
            'last_seen_at' => '2026-04-01T11:00:00+00:00',
            'message_count' => 3,
        ]));
        file_put_contents($sessionDir . '/newer-session.json', json_encode([
            'created_at' => '2026-05-01T10:00:00+00:00',
            'last_seen_at' => '2026-05-01T11:30:00+00:00',
            'message_count' => 7,
        ]));

        $sessions = $launcher->listSessions($worktree);

        if (count($sessions) !== 2) {
            echo "FAIL testListSessionsFallsBackToDirectory: expected 2 sessions, got " . count($sessions) . "\n";
            return 1;
        }
        // Sorted by recency desc: newer-session first
        if ($sessions[0]->id !== 'newer-session' || $sessions[0]->messageCount !== 7) {
            echo "FAIL testListSessionsFallsBackToDirectory: unexpected order or metadata\n";
            return 1;
        }

        echo "OK testListSessionsFallsBackToDirectory\n";
        return 0;
    }

    private function testListSessionsReturnsEmptyWhenGeminiSucceedsWithNoSessions(): int
    {
        // run() returns "" (exit 0, empty output) — the CLI succeeded but there are no sessions.
        // We must NOT fall back to the directory; return empty list to respect the CLI answer.
        $launcher = new GeminiAgentLauncher($this->makeProcessRunner(true, ''), $this->tmpDir);

        $worktree = $this->makeWorktree('no-sessions');
        // Plant a stale directory entry that must NOT be returned
        $projectHash = hash('sha256', realpath($worktree) ?: $worktree);
        $sessionDir = $this->tmpDir . '/.gemini/tmp/' . $projectHash;
        mkdir($sessionDir, 0755, true);
        file_put_contents($sessionDir . '/stale.json', json_encode(['message_count' => 1]));

        $sessions = $launcher->listSessions($worktree);

        if ($sessions !== []) {
            echo "FAIL testListSessionsReturnsEmptyWhenGeminiSucceedsWithNoSessions: expected empty list, got " . count($sessions) . " session(s)\n";
            return 1;
        }

        echo "OK testListSessionsReturnsEmptyWhenGeminiSucceedsWithNoSessions\n";
        return 0;
    }

    private function testCaptureCurrentSessionIdReturnsFirstFromList(): int
    {
        $tableOutput = "latest-session   2026-05-03T12:00:00Z\nolder-session    2026-05-01T09:00:00Z";
        $launcher = new GeminiAgentLauncher($this->makeProcessRunner(true, $tableOutput), $this->tmpDir);

        $id = $launcher->captureCurrentSessionId('/fake-worktree');

        if ($id !== 'latest-session') {
            echo "FAIL testCaptureCurrentSessionIdReturnsFirstFromList: expected 'latest-session', got " . var_export($id, true) . "\n";
            return 1;
        }

        echo "OK testCaptureCurrentSessionIdReturnsFirstFromList\n";
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

    private function makeProcessRunner(bool $succeeds, ?string $runOutput = null): ProcessRunner
    {
        return new class($succeeds, $runOutput) implements ProcessRunner {
            /**
             * @param bool $succeeds Availability result returned by succeeds()
             * @param string|null $runOutput Output returned by run(), or null to simulate failure
             */
            public function __construct(private bool $succeeds, private ?string $runOutput) {}

            /**
             * Returns the configured availability result.
             */
            public function succeeds(string $command): bool
            {
                return $this->succeeds;
            }

            /**
             * Returns the configured output.
             */
            public function output(string $command, string $cwd = ''): ?string
            {
                return $this->runOutput;
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
