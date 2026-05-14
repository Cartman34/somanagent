<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Test;

use SoManAgent\Script\Backlog\Agent\Command\AgentStopCommand;
use SoManAgent\Script\Backlog\Agent\Enum\AgentClient;
use SoManAgent\Script\Backlog\Agent\Enum\AgentRole;
use SoManAgent\Script\Backlog\Agent\Model\AgentSession;
use SoManAgent\Script\Backlog\Agent\Service\AgentSessionService;
use SoManAgent\Script\Console;

/**
 * Unit tests for AgentStopCommand.
 *
 * Exercises the alive-check/stop/cleanup flow using a FakeSessionDriver,
 * so no real processes or tmux sessions are touched.
 */
final class AgentStopCommandTest
{
    private string $tmpDir;

    /**
     * Creates a temporary directory used by each test for an isolated sessions.json.
     */
    public function __construct()
    {
        $this->tmpDir = sys_get_temp_dir() . '/backlog-agent-stop-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
    }

    /**
     * Removes the temporary directory on cleanup.
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

        $failed += $this->testStopsAliveSessionAndRemovesEntry();
        $failed += $this->testRefusesDeadSessionWithoutCleanup();
        $failed += $this->testCleanupRemovesStaleSession();
        $failed += $this->testUpdatesLastSeenOnObservation();

        return $failed;
    }

    private function testStopsAliveSessionAndRemovesEntry(): int
    {
        $dir = $this->tmpDir . '/alive-' . uniqid('', true);
        mkdir($dir, 0755, true);
        $service = new AgentSessionService($dir);
        $service->add($this->makeSession('d01'));

        $driver = new FakeSessionDriver();
        $driver->setAlive('d01', true);

        $cmd = new AgentStopCommand(Console::getInstance(), $service, $driver);
        $cmd->handle([], ['code' => 'd01']);

        if ($driver->lastStoppedSession === null || $driver->lastStoppedSession->code !== 'd01') {
            echo "FAIL testStopsAliveSessionAndRemovesEntry: driver->stop() was not called with d01\n";
            $this->rmdir($dir);
            return 1;
        }
        if ($service->has('d01')) {
            echo "FAIL testStopsAliveSessionAndRemovesEntry: session not removed after stop\n";
            $this->rmdir($dir);
            return 1;
        }
        echo "OK testStopsAliveSessionAndRemovesEntry\n";
        $this->rmdir($dir);
        return 0;
    }

    private function testRefusesDeadSessionWithoutCleanup(): int
    {
        $dir = $this->tmpDir . '/dead-' . uniqid('', true);
        mkdir($dir, 0755, true);
        $service = new AgentSessionService($dir);
        $service->add($this->makeSession('d01'));

        $driver = new FakeSessionDriver();
        // d01 not alive by default

        $cmd = new AgentStopCommand(Console::getInstance(), $service, $driver);

        $threw = false;
        try {
            $cmd->handle([], ['code' => 'd01']);
        } catch (\RuntimeException $e) {
            $threw = str_contains($e->getMessage(), '--cleanup');
        }

        if (!$threw) {
            echo "FAIL testRefusesDeadSessionWithoutCleanup: expected RuntimeException mentioning --cleanup\n";
            $this->rmdir($dir);
            return 1;
        }
        if (!$service->has('d01')) {
            echo "FAIL testRefusesDeadSessionWithoutCleanup: session removed when it should have stayed\n";
            $this->rmdir($dir);
            return 1;
        }
        echo "OK testRefusesDeadSessionWithoutCleanup\n";
        $this->rmdir($dir);
        return 0;
    }

    private function testCleanupRemovesStaleSession(): int
    {
        $dir = $this->tmpDir . '/cleanup-' . uniqid('', true);
        mkdir($dir, 0755, true);
        $service = new AgentSessionService($dir);
        $service->add($this->makeSession('d01'));

        $driver = new FakeSessionDriver();
        // d01 not alive

        $cmd = new AgentStopCommand(Console::getInstance(), $service, $driver);
        $cmd->handle([], ['code' => 'd01', 'cleanup' => true]);

        if ($service->has('d01')) {
            echo "FAIL testCleanupRemovesStaleSession: session not removed with --cleanup\n";
            $this->rmdir($dir);
            return 1;
        }
        if ($driver->lastStoppedSession !== null) {
            echo "FAIL testCleanupRemovesStaleSession: stop() should not be called for a dead session\n";
            $this->rmdir($dir);
            return 1;
        }
        echo "OK testCleanupRemovesStaleSession\n";
        $this->rmdir($dir);
        return 0;
    }

    private function testUpdatesLastSeenOnObservation(): int
    {
        $dir = $this->tmpDir . '/lastseen-stop-' . uniqid('', true);
        mkdir($dir, 0755, true);
        $service = new AgentSessionService($dir);

        $past = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $session = new AgentSession(
            code: 'd01',
            client: AgentClient::CLAUDE,
            role: AgentRole::DEVELOPER,
            pid: 1100,
            worktree: '/tmp',
            startedAt: $past,
            lastSeenAt: $past,
            sessionId: null,
        );
        $service->add($session);

        // Dead session, no --cleanup: stop refuses but should still refresh last_seen_at.
        $driver = new FakeSessionDriver();

        $cmd = new AgentStopCommand(Console::getInstance(), $service, $driver);
        try {
            $cmd->handle([], ['code' => 'd01']);
            echo "FAIL testUpdatesLastSeenOnObservation: expected refusal on dead session without --cleanup\n";
            $this->rmdir($dir);
            return 1;
        } catch (\RuntimeException) {
            // expected
        }

        $reloaded = $service->get('d01');
        if ($reloaded === null || $reloaded->lastSeenAt <= $past) {
            echo "FAIL testUpdatesLastSeenOnObservation: last_seen_at not refreshed\n";
            $this->rmdir($dir);
            return 1;
        }
        echo "OK testUpdatesLastSeenOnObservation\n";
        $this->rmdir($dir);
        return 0;
    }

    private function makeSession(string $code): AgentSession
    {
        $now = new \DateTimeImmutable();
        return new AgentSession(
            code: $code,
            client: AgentClient::CLAUDE,
            role: AgentRole::DEVELOPER,
            pid: 42,
            worktree: '/tmp/fake',
            startedAt: $now,
            lastSeenAt: $now,
            sessionId: null,
            clientPid: null,
        );
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
