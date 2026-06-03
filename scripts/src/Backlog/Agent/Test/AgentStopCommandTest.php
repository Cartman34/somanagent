<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Agent\Test;

use Sowapps\SoManAgent\Script\Backlog\Agent\Service\AgentSessionService;
use Sowapps\SoManAgent\Script\Backlog\Agent\Command\AgentStopCommand;
use Sowapps\SoManAgent\Script\Console;
use Sowapps\SoManAgent\Script\Backlog\Agent\Model\AgentSession;
use Sowapps\SoManAgent\Script\Backlog\Agent\Enum\AgentClient;
use Sowapps\SoManAgent\Script\Backlog\Agent\Enum\AgentRole;
use Sowapps\SoManAgent\Script\Backlog\Agent\Test\FakeSessionDriver;
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
        $failed += $this->testStopsOrphanDriverSessionWithoutRegistryEntry();
        $failed += $this->testRefusesWhenNeitherRegistryNorDriver();

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

    /**
     * No registry entry for d11, but the driver reports a live session (orphan tmux session).
     * stop must call kill() directly and exit 0 with a dedicated message.
     */
    private function testStopsOrphanDriverSessionWithoutRegistryEntry(): int
    {
        $dir = $this->tmpDir . '/orphan-driver-' . uniqid('', true);
        mkdir($dir, 0755, true);
        $service = new AgentSessionService($dir);

        $driver = new FakeSessionDriver();
        $driver->setExists('d11', true);

        $cmd = new AgentStopCommand(Console::getInstance(), $service, $driver);

        ob_start();
        $exit = $cmd->handle([], ['code' => 'd11']);
        $output = (string) ob_get_clean();

        if ($exit !== 0) {
            echo "FAIL testStopsOrphanDriverSessionWithoutRegistryEntry: exit code {$exit}, expected 0\n";
            $this->rmdir($dir);
            return 1;
        }
        if (!in_array('d11', $driver->killedCodes, true)) {
            echo "FAIL testStopsOrphanDriverSessionWithoutRegistryEntry: kill(d11) was not called\n";
            $this->rmdir($dir);
            return 1;
        }
        if (!str_contains($output, 'orphan driver session')) {
            echo "FAIL testStopsOrphanDriverSessionWithoutRegistryEntry: output missing 'orphan driver session' message\n";
            $this->rmdir($dir);
            return 1;
        }
        echo "OK testStopsOrphanDriverSessionWithoutRegistryEntry\n";
        $this->rmdir($dir);
        return 0;
    }

    /**
     * No registry entry AND the driver reports no live session → throw with a clear error.
     */
    private function testRefusesWhenNeitherRegistryNorDriver(): int
    {
        $dir = $this->tmpDir . '/neither-' . uniqid('', true);
        mkdir($dir, 0755, true);
        $service = new AgentSessionService($dir);

        $driver = new FakeSessionDriver();
        // sessionExists returns false by default

        $cmd = new AgentStopCommand(Console::getInstance(), $service, $driver);

        $threw = false;
        try {
            $cmd->handle([], ['code' => 'd11']);
        } catch (\RuntimeException $e) {
            $threw = str_contains($e->getMessage(), "No session found for code 'd11'");
        }

        if (!$threw) {
            echo "FAIL testRefusesWhenNeitherRegistryNorDriver: expected RuntimeException mentioning 'No session found'\n";
            $this->rmdir($dir);
            return 1;
        }
        echo "OK testRefusesWhenNeitherRegistryNorDriver\n";
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
