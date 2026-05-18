<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Test;

use SoManAgent\Script\Backlog\Agent\Client\CodexAgentLauncher;
use SoManAgent\Script\Backlog\Agent\Client\ProcessRunner;
use SoManAgent\Script\Backlog\Agent\Enum\AgentClient;
use SoManAgent\Script\Backlog\Agent\Enum\AgentRole;

/**
 * Unit tests for CodexAgentLauncher hooks.
 */
final class CodexAgentLauncherTest
{
    /**
     * Temporary root containing generated context files, worktrees, and Codex rollout fixtures.
     */
    private string $tmpDir;

    /**
     * Creates a temporary test root used for context and Codex rollout fixtures.
     */
    public function __construct()
    {
        $this->tmpDir = sys_get_temp_dir() . '/backlog-agent-codex-test-' . uniqid('', true);
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
        $failed += $this->testBuildLaunchCommandInitialPrompt();
        $failed += $this->testBuildLaunchCommandFailsWhenContextIsMissing();
        $failed += $this->testBuildLaunchCommandContinue();
        $failed += $this->testBuildLaunchCommandResumeId();
        $failed += $this->testBuildLaunchCommandIncludesApprovalFlags();
        $failed += $this->testCaptureCurrentSessionId();
        $failed += $this->testListSessionsParsesCodexRollouts();
        $failed += $this->testListSessionsSkipsCompressedRolloutWithoutZstd();

        return $failed;
    }

    private function testClient(): int
    {
        $launcher = new CodexAgentLauncher($this->makeProcessRunner(true), $this->tmpDir);

        if ($launcher->client() !== AgentClient::CODEX) {
            echo "FAIL testClient: expected CODEX\n";
            return 1;
        }

        echo "OK testClient\n";
        return 0;
    }

    private function testIsAvailableUsesProcessRunner(): int
    {
        $runner = $this->makeProcessRunner(false);
        $launcher = new CodexAgentLauncher($runner, $this->tmpDir);

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
        $launcher = new CodexAgentLauncher($this->makeProcessRunner(true), $this->tmpDir);

        [$bin, $args] = $launcher->buildLaunchCommand('/worktree', $context, AgentRole::DEVELOPER);

        $expected = ['-C', '/worktree', '--ask-for-approval', 'never', "context initial\n\n--- Begin session ---"];
        if ($bin !== 'codex' || $args !== $expected) {
            echo "FAIL testBuildLaunchCommandInitial: unexpected command\n";
            return 1;
        }

        echo "OK testBuildLaunchCommandInitial\n";
        return 0;
    }

    private function testBuildLaunchCommandInitialPrompt(): int
    {
        $context = $this->writeContext('context initial');
        $launcher = new CodexAgentLauncher($this->makeProcessRunner(true), $this->tmpDir);

        [$bin, $args] = $launcher->buildLaunchCommand(
            '/worktree',
            $context,
            AgentRole::DEVELOPER,
            null,
            false,
            null,
            'initial user prompt',
        );

        $expected = ['-C', '/worktree', '--ask-for-approval', 'never', "context initial\n\n--- Begin session ---\n\ninitial user prompt"];
        if ($bin !== 'codex' || $args !== $expected) {
            echo "FAIL testBuildLaunchCommandInitialPrompt: unexpected command\n";
            return 1;
        }

        echo "OK testBuildLaunchCommandInitialPrompt\n";
        return 0;
    }

    private function testBuildLaunchCommandFailsWhenContextIsMissing(): int
    {
        $launcher = new CodexAgentLauncher($this->makeProcessRunner(true), $this->tmpDir);

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
        $launcher = new CodexAgentLauncher($this->makeProcessRunner(true), $this->tmpDir);

        [$bin, $args] = $launcher->buildLaunchCommand('/worktree', $this->tmpDir . '/missing-context.md', AgentRole::DEVELOPER, null, true);

        if ($bin !== 'codex' || $args !== ['-C', '/worktree', '--ask-for-approval', 'never', 'resume', '--last']) {
            echo "FAIL testBuildLaunchCommandContinue: unexpected continue command\n";
            return 1;
        }

        echo "OK testBuildLaunchCommandContinue\n";
        return 0;
    }

    private function testBuildLaunchCommandResumeId(): int
    {
        $launcher = new CodexAgentLauncher($this->makeProcessRunner(true), $this->tmpDir);

        [$bin, $args] = $launcher->buildLaunchCommand('/worktree', $this->tmpDir . '/missing-context.md', AgentRole::DEVELOPER, 'session-123');

        if ($bin !== 'codex' || $args !== ['-C', '/worktree', '--ask-for-approval', 'never', 'resume', 'session-123']) {
            echo "FAIL testBuildLaunchCommandResumeId: unexpected resume command\n";
            return 1;
        }

        echo "OK testBuildLaunchCommandResumeId\n";
        return 0;
    }

    private function testBuildLaunchCommandIncludesApprovalFlags(): int
    {
        $context = $this->writeContext('perm context');
        $launcher = new CodexAgentLauncher($this->makeProcessRunner(true), $this->tmpDir);

        foreach ([
            'initial' => $launcher->buildLaunchCommand('/worktree', $context, AgentRole::DEVELOPER),
            'continue' => $launcher->buildLaunchCommand('/worktree', $context, AgentRole::DEVELOPER, null, true),
            'resume' => $launcher->buildLaunchCommand('/worktree', $context, AgentRole::DEVELOPER, 'session-x'),
        ] as $mode => [, $args]) {
            if (!in_array('--ask-for-approval', $args, true) || !in_array('never', $args, true)) {
                echo "FAIL testBuildLaunchCommandIncludesApprovalFlags ($mode): --ask-for-approval never missing\n";
                return 1;
            }
        }

        echo "OK testBuildLaunchCommandIncludesApprovalFlags\n";
        return 0;
    }

    private function testCaptureCurrentSessionId(): int
    {
        $worktree = $this->makeWorktree('capture');
        $this->writeRollout('2026/01/01', 'old-id', $worktree, '2026-01-01T10:00:00+00:00', 'old prompt');
        $this->writeRollout('2026/01/02', 'new-id', $worktree, '2026-01-02T10:00:00+00:00', 'new prompt');

        $launcher = new CodexAgentLauncher($this->makeProcessRunner(true), $this->tmpDir);

        if ($launcher->captureCurrentSessionId($worktree) !== 'new-id') {
            echo "FAIL testCaptureCurrentSessionId: expected newest rollout id\n";
            return 1;
        }

        echo "OK testCaptureCurrentSessionId\n";
        return 0;
    }

    private function testListSessionsParsesCodexRollouts(): int
    {
        $worktree = $this->makeWorktree('sessions');
        $otherWorktree = $this->makeWorktree('other');
        $this->writeRollout('2026/01/01', 'session-a', $worktree, '2026-01-01T10:00:00+00:00', str_repeat('A', 90));
        $this->writeRollout('2026/01/02', 'session-b', $worktree, '2026-01-02T10:00:00+00:00', 'second prompt');
        $this->writeRollout('2026/01/03', 'ignored', $otherWorktree, '2026-01-03T10:00:00+00:00', 'other prompt');

        $launcher = new CodexAgentLauncher($this->makeProcessRunner(true), $this->tmpDir);
        $sessions = $launcher->listSessions($worktree);

        if (count($sessions) !== 2 || $sessions[0]->id !== 'session-b' || $sessions[1]->id !== 'session-a') {
            echo "FAIL testListSessionsParsesCodexRollouts: unexpected order or count\n";
            return 1;
        }
        if ($sessions[1]->messageCount !== 2 || $sessions[1]->startedAt?->format(DATE_ATOM) !== '2026-01-01T10:00:00+00:00') {
            echo "FAIL testListSessionsParsesCodexRollouts: unexpected metadata\n";
            return 1;
        }
        if ($sessions[1]->firstPromptExcerpt !== str_repeat('A', 80)) {
            echo "FAIL testListSessionsParsesCodexRollouts: prompt excerpt not truncated\n";
            return 1;
        }

        echo "OK testListSessionsParsesCodexRollouts\n";
        return 0;
    }

    private function testListSessionsSkipsCompressedRolloutWithoutZstd(): int
    {
        $worktree = $this->makeWorktree('compressed');
        $path = $this->tmpDir . '/.codex/sessions/2026/01/04/rollout-compressed.jsonl.zst';
        mkdir(dirname($path), 0755, true);
        file_put_contents($path, 'compressed fixture');

        $warnings = [];
        $launcher = new CodexAgentLauncher($this->makeProcessRunner(false), $this->tmpDir, static function (string $message) use (&$warnings): void {
            $warnings[] = $message;
        });

        if ($launcher->listSessions($worktree) !== []) {
            echo "FAIL testListSessionsSkipsCompressedRolloutWithoutZstd: expected no sessions\n";
            return 1;
        }
        if (count($warnings) !== 1 || !str_contains($warnings[0], 'skipping compressed Codex rollout without zstd')) {
            echo "FAIL testListSessionsSkipsCompressedRolloutWithoutZstd: expected warning\n";
            return 1;
        }

        echo "OK testListSessionsSkipsCompressedRolloutWithoutZstd\n";
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
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        return $path;
    }

    private function writeRollout(string $datePath, string $id, string $worktree, string $timestamp, string $prompt): void
    {
        $dir = $this->tmpDir . '/.codex/sessions/' . $datePath;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($dir . '/rollout-' . $id . '.jsonl', implode("\n", [
            json_encode(['timestamp' => $timestamp, 'type' => 'session_meta', 'payload' => ['id' => $id, 'timestamp' => $timestamp, 'cwd' => realpath($worktree) ?: $worktree]]),
            json_encode(['timestamp' => $timestamp, 'type' => 'response_item', 'payload' => ['type' => 'message', 'role' => 'user', 'content' => [['type' => 'input_text', 'text' => $prompt]]]]),
            json_encode(['timestamp' => $timestamp, 'type' => 'response_item', 'payload' => ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'answer']]]]),
            '',
        ]));
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
             * Not used by CodexAgentLauncher.
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
