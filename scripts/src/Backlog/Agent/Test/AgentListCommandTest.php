<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Test;

use SoManAgent\Script\Backlog\Agent\Client\SessionDriverInterface;
use SoManAgent\Script\Backlog\Agent\Command\AgentListCommand;
use SoManAgent\Script\Backlog\Agent\Enum\AgentClient;
use SoManAgent\Script\Backlog\Agent\Enum\AgentRole;
use SoManAgent\Script\Backlog\Agent\Model\AgentSession;
use SoManAgent\Script\Backlog\Agent\Service\AgentSessionService;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Client\FilesystemClient;
use SoManAgent\Script\Console;
use SoManAgent\Script\TextSlugger;

/**
 * Command-level tests for {@see AgentListCommand}.
 *
 * Covers: empty sessions, default alive-only filter, --running, --all, and the
 * `current` column derivation for developer and reviewer sessions. Output is
 * captured with PHP output buffering since Console writes directly to stdout.
 */
final class AgentListCommandTest
{
    private string $tmpDir;

    /**
     * Creates temp directory for test fixtures.
     */
    public function __construct()
    {
        $this->tmpDir = sys_get_temp_dir() . '/backlog-agent-list-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
    }

    /**
     * Removes temp directory and all its contents.
     */
    public function __destruct()
    {
        $this->rmdir($this->tmpDir);
    }

    /**
     * Runs every test case and returns the cumulative number of failures.
     */
    public function run(): int
    {
        $failed = 0;

        $failed += $this->testPrintsNoSessionsWhenEmpty();
        $failed += $this->testDefaultHidesDeadSessions();
        $failed += $this->testRunningFilterShowsOnlyAlive();
        $failed += $this->testAllIncludesDeadSessions();
        $failed += $this->testDerivesDeveloperEntryFromBoard();
        $failed += $this->testDerivesReviewerEntryFromBoard();
        $failed += $this->testDerivesManagerLabelWithBoard();
        $failed += $this->testDerivesManagerLabelWithMissingBoard();
        $failed += $this->testRefreshesLastSeenOnInspection();

        return $failed;
    }

    private function testPrintsNoSessionsWhenEmpty(): int
    {
        $dir = $this->scratch('empty');
        $service = new AgentSessionService($dir);

        $output = $this->captureHandle($this->buildCommand($service, $dir, $dir . '/board.md'), []);

        if (!str_contains($output, 'No agent sessions.')) {
            echo "FAIL testPrintsNoSessionsWhenEmpty: missing 'No agent sessions.' in output\n{$output}\n";
            return 1;
        }
        echo "OK testPrintsNoSessionsWhenEmpty\n";
        return 0;
    }

    private function testDefaultHidesDeadSessions(): int
    {
        $dir = $this->scratch('default-alive');
        $service = new AgentSessionService($dir);
        $service->add($this->makeSession('d-alive', AgentRole::DEVELOPER, pid: getmypid() ?: 1));
        $service->add($this->makeSession('d-dead', AgentRole::DEVELOPER, pid: 0));

        $output = $this->captureHandle($this->buildCommand($service, $dir, $dir . '/missing-board.md'), []);

        if (!str_contains($output, 'd-alive')) {
            echo "FAIL testDefaultHidesDeadSessions: missing alive session in output\n{$output}\n";
            return 1;
        }
        if (str_contains($output, 'd-dead')) {
            echo "FAIL testDefaultHidesDeadSessions: dead session leaked into default output\n{$output}\n";
            return 1;
        }
        echo "OK testDefaultHidesDeadSessions\n";
        return 0;
    }

    private function testRunningFilterShowsOnlyAlive(): int
    {
        $dir = $this->scratch('running-filter');
        $service = new AgentSessionService($dir);
        $service->add($this->makeSession('d-alive', AgentRole::DEVELOPER, pid: getmypid() ?: 1));
        $service->add($this->makeSession('d-dead', AgentRole::DEVELOPER, pid: 0));

        $output = $this->captureHandle($this->buildCommand($service, $dir, $dir . '/missing.md'), ['running' => true]);

        if (!str_contains($output, 'd-alive') || str_contains($output, 'd-dead')) {
            echo "FAIL testRunningFilterShowsOnlyAlive: unexpected filter result\n{$output}\n";
            return 1;
        }
        echo "OK testRunningFilterShowsOnlyAlive\n";
        return 0;
    }

    private function testAllIncludesDeadSessions(): int
    {
        $dir = $this->scratch('all-filter');
        $service = new AgentSessionService($dir);
        $service->add($this->makeSession('d-alive', AgentRole::DEVELOPER, pid: getmypid() ?: 1));
        $service->add($this->makeSession('d-dead', AgentRole::DEVELOPER, pid: 0));

        $output = $this->captureHandle($this->buildCommand($service, $dir, $dir . '/missing.md'), ['all' => true]);

        if (!str_contains($output, 'd-alive') || !str_contains($output, 'd-dead')) {
            echo "FAIL testAllIncludesDeadSessions: --all should include both alive and dead sessions\n{$output}\n";
            return 1;
        }
        if (!str_contains($output, 'dead')) {
            echo "FAIL testAllIncludesDeadSessions: dead label missing from PID column\n{$output}\n";
            return 1;
        }
        echo "OK testAllIncludesDeadSessions\n";
        return 0;
    }

    private function testDerivesDeveloperEntryFromBoard(): int
    {
        $dir = $this->scratch('derive-dev');
        $service = new AgentSessionService($dir);
        $service->add($this->makeSession('d01', AgentRole::DEVELOPER, pid: getmypid() ?: 1));

        $boardPath = $dir . '/board.md';
        $this->writeBoard($boardPath, [
            '- some-feature',
            '  meta:',
            '    kind: feature',
            '    feature: some-feature',
            '    branch: feat/some-feature',
            '    type: feat',
            '    stage: development',
            '    agent: d01',
        ]);

        $output = $this->captureHandle($this->buildCommand($service, $dir, $boardPath), []);

        if (!str_contains($output, 'some-feature')) {
            echo "FAIL testDerivesDeveloperEntryFromBoard: current label missing\n{$output}\n";
            return 1;
        }
        echo "OK testDerivesDeveloperEntryFromBoard\n";
        return 0;
    }

    private function testDerivesReviewerEntryFromBoard(): int
    {
        $dir = $this->scratch('derive-rev');
        $service = new AgentSessionService($dir);
        $service->add($this->makeSession('r01', AgentRole::REVIEWER, pid: getmypid() ?: 1));

        $boardPath = $dir . '/board.md';
        $this->writeBoard($boardPath, [
            '- review-feature',
            '  meta:',
            '    kind: feature',
            '    feature: review-feature',
            '    branch: feat/review-feature',
            '    type: feat',
            '    stage: reviewing',
            '    agent: d02',
            '    reviewer: r01',
        ]);

        $output = $this->captureHandle($this->buildCommand($service, $dir, $boardPath), []);

        if (!str_contains($output, '[reviewing] review-feature')) {
            echo "FAIL testDerivesReviewerEntryFromBoard: expected '[reviewing] review-feature' in output\n{$output}\n";
            return 1;
        }
        echo "OK testDerivesReviewerEntryFromBoard\n";
        return 0;
    }

    private function testDerivesManagerLabelWithBoard(): int
    {
        $dir = $this->scratch('manager-board');
        $service = new AgentSessionService($dir);
        $worktree = $this->tmpDir . '/wa-m01';
        $service->add(new AgentSession(
            code: 'm01',
            client: AgentClient::CLAUDE,
            role: AgentRole::MANAGER,
            pid: getmypid() ?: 1,
            worktree: $worktree,
            startedAt: new \DateTimeImmutable(),
            lastSeenAt: new \DateTimeImmutable(),
            sessionId: null,
        ));

        $boardPath = $dir . '/board.md';
        $this->writeBoard($boardPath, []);

        $output = $this->captureHandle($this->buildCommand($service, $dir, $boardPath), ['all' => true]);

        if (!str_contains($output, 'manager ' . $worktree)) {
            echo "FAIL testDerivesManagerLabelWithBoard: expected 'manager {$worktree}' in output\n{$output}\n";
            return 1;
        }
        echo "OK testDerivesManagerLabelWithBoard\n";
        return 0;
    }

    private function testDerivesManagerLabelWithMissingBoard(): int
    {
        $dir = $this->scratch('manager-no-board');
        $service = new AgentSessionService($dir);
        $worktree = $this->tmpDir . '/wa-m02';
        $service->add(new AgentSession(
            code: 'm02',
            client: AgentClient::CLAUDE,
            role: AgentRole::MANAGER,
            pid: getmypid() ?: 1,
            worktree: $worktree,
            startedAt: new \DateTimeImmutable(),
            lastSeenAt: new \DateTimeImmutable(),
            sessionId: null,
        ));

        $output = $this->captureHandle($this->buildCommand($service, $dir, $dir . '/missing.md'), ['all' => true]);

        if (!str_contains($output, 'manager ' . $worktree)) {
            echo "FAIL testDerivesManagerLabelWithMissingBoard: expected 'manager {$worktree}' even without board\n{$output}\n";
            return 1;
        }
        echo "OK testDerivesManagerLabelWithMissingBoard\n";
        return 0;
    }

    private function testRefreshesLastSeenOnInspection(): int
    {
        $dir = $this->scratch('lastseen');
        $service = new AgentSessionService($dir);
        $past = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $service->add(new AgentSession(
            code: 'd01',
            client: AgentClient::CLAUDE,
            role: AgentRole::DEVELOPER,
            pid: 0,
            worktree: $dir . '/fake',
            startedAt: $past,
            lastSeenAt: $past,
            sessionId: null,
        ));

        $this->captureHandle($this->buildCommand($service, $dir, $dir . '/missing.md'), ['all' => true]);

        $reloaded = $service->get('d01');
        if ($reloaded === null || $reloaded->lastSeenAt <= $past) {
            echo "FAIL testRefreshesLastSeenOnInspection: last_seen_at not refreshed\n";
            return 1;
        }
        echo "OK testRefreshesLastSeenOnInspection\n";
        return 0;
    }

    /**
     * Builds the command under test with real (file-backed) board and session services.
     */
    private function buildCommand(AgentSessionService $service, string $projectRoot, string $boardPath): AgentListCommand
    {
        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);

        return new AgentListCommand(
            Console::getInstance(),
            $projectRoot,
            $boardPath,
            $service,
            $boardService,
            $this->defaultDriver(),
        );
    }

    /**
     * Returns a SessionDriverInterface that considers a session alive when its pid != 0.
     *
     * Preserves the convention used by makeSession(): pass getmypid() for alive, 0 for dead.
     */
    private function defaultDriver(): SessionDriverInterface
    {
        return new class implements SessionDriverInterface {
            public function checkDependencies(): void {}

            public function sessionExists(string $agentCode): bool
            {
                return false;
            }

            public function launch(string $agentCode, string $bin, array $args, string $cwd, array $env, callable $onSpawned): int
            {
                return 0;
            }

            public function resume(string $agentCode, string $bin, array $args, string $cwd, array $env, callable $onSpawned): int
            {
                return 0;
            }

            public function stop(AgentSession $session): void {}

            public function isAlive(AgentSession $session): bool
            {
                return $session->pid !== 0;
            }
        };
    }

    /**
     * Captures stdout while running the command handler.
     *
     * @param array<string, bool|string|array<bool|string>> $options
     */
    private function captureHandle(AgentListCommand $command, array $options): string
    {
        ob_start();
        try {
            $command->handle([], $options);
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }

    /**
     * Builds an AgentSession with controllable liveness via the wrapper pid.
     *
     * Pass `getmypid()` for an "alive" session — defaultSignaler() marks that PID alive.
     * Pass `0` for a "dead" session — FakeProcessSignaler returns false for unknown PIDs.
     */
    private function makeSession(string $code, AgentRole $role, int $pid): AgentSession
    {
        $now = new \DateTimeImmutable();
        return new AgentSession(
            code: $code,
            client: AgentClient::CLAUDE,
            role: $role,
            pid: $pid,
            worktree: $this->tmpDir . '/wa-' . $code,
            startedAt: $now,
            lastSeenAt: $now,
            sessionId: null,
        );
    }

    /**
     * Writes a minimal backlog board file with the given active entry lines.
     *
     * @param list<string> $activeLines
     */
    private function writeBoard(string $path, array $activeLines): void
    {
        $content = "# Test backlog\n\n## To do\n\n## In progress\n\n"
            . implode("\n", $activeLines)
            . "\n\n## Suggestions\n";
        file_put_contents($path, $content);
    }

    /**
     * Returns a fresh scratch sub-directory under $tmpDir.
     */
    private function scratch(string $label): string
    {
        $path = $this->tmpDir . '/' . $label . '-' . uniqid('', true);
        mkdir($path, 0755, true);
        return $path;
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
