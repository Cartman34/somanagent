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
 * Exercises the SIGTERM/SIGKILL flow using a FakeProcessSignaler, so no real processes are touched.
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

        $failed += $this->testTargetsProcessGroupBeforeClientPid();
        $failed += $this->testFallbackToClientPidWhenNoProcessGroup();
        $failed += $this->testSigkillFollowupWhenSigtermIgnored();
        $failed += $this->testRefusesDeadSessionWithoutCleanup();
        $failed += $this->testCleanupRemovesStaleSession();
        $failed += $this->testUpdatesLastSeenOnObservation();

        return $failed;
    }

    private function testTargetsProcessGroupBeforeClientPid(): int
    {
        $dir = $this->tmpDir . '/pgid-' . uniqid('', true);
        mkdir($dir, 0755, true);
        $service = new AgentSessionService($dir);
        $service->add($this->makeSession('d01', clientPid: 555, processGroupId: 555));

        $signaler = new FakeProcessSignaler();
        $signaler->setAlive(555, true);
        $signaler->sigtermKills = true;

        $cmd = new AgentStopCommand(Console::getInstance(), $service, $signaler, 1);
        $cmd->handle([], ['code' => 'd01']);

        if (count($signaler->signals) < 1 || $signaler->signals[0]['pid'] !== -555 || $signaler->signals[0]['signal'] !== SIGTERM) {
            echo "FAIL testTargetsProcessGroupBeforeClientPid: expected SIGTERM to -555, got "
                . var_export($signaler->signals, true) . "\n";
            $this->rmdir($dir);
            return 1;
        }
        if ($service->has('d01')) {
            echo "FAIL testTargetsProcessGroupBeforeClientPid: session not removed\n";
            $this->rmdir($dir);
            return 1;
        }
        echo "OK testTargetsProcessGroupBeforeClientPid\n";
        $this->rmdir($dir);
        return 0;
    }

    private function testFallbackToClientPidWhenNoProcessGroup(): int
    {
        $dir = $this->tmpDir . '/clientpid-' . uniqid('', true);
        mkdir($dir, 0755, true);
        $service = new AgentSessionService($dir);
        $service->add($this->makeSession('d01', clientPid: 700, processGroupId: null));

        $signaler = new FakeProcessSignaler();
        $signaler->setAlive(700, true);

        $cmd = new AgentStopCommand(Console::getInstance(), $service, $signaler, 1);
        $cmd->handle([], ['code' => 'd01']);

        if ($signaler->signals === [] || $signaler->signals[0]['pid'] !== 700) {
            echo "FAIL testFallbackToClientPidWhenNoProcessGroup: expected signal to client PID 700\n";
            $this->rmdir($dir);
            return 1;
        }
        echo "OK testFallbackToClientPidWhenNoProcessGroup\n";
        $this->rmdir($dir);
        return 0;
    }

    private function testSigkillFollowupWhenSigtermIgnored(): int
    {
        $dir = $this->tmpDir . '/sigkill-' . uniqid('', true);
        mkdir($dir, 0755, true);
        $service = new AgentSessionService($dir);
        $service->add($this->makeSession('d01', clientPid: 800, processGroupId: 800));

        $signaler = new FakeProcessSignaler();
        $signaler->setAlive(800, true);
        $signaler->sigtermKills = false; // simulate stuck client

        $cmd = new AgentStopCommand(Console::getInstance(), $service, $signaler, 1);
        $cmd->handle([], ['code' => 'd01']);

        $signals = array_map(fn(array $s): int => $s['signal'], $signaler->signals);
        if (!in_array(SIGTERM, $signals, true) || !in_array(SIGKILL, $signals, true)) {
            echo "FAIL testSigkillFollowupWhenSigtermIgnored: expected SIGTERM then SIGKILL, got "
                . var_export($signals, true) . "\n";
            $this->rmdir($dir);
            return 1;
        }
        echo "OK testSigkillFollowupWhenSigtermIgnored\n";
        $this->rmdir($dir);
        return 0;
    }

    private function testRefusesDeadSessionWithoutCleanup(): int
    {
        $dir = $this->tmpDir . '/dead-' . uniqid('', true);
        mkdir($dir, 0755, true);
        $service = new AgentSessionService($dir);
        $service->add($this->makeSession('d01', clientPid: 900, processGroupId: 900));

        $signaler = new FakeProcessSignaler(); // 900 not alive

        $cmd = new AgentStopCommand(Console::getInstance(), $service, $signaler, 1);

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
        $service->add($this->makeSession('d01', clientPid: 1000, processGroupId: 1000));

        $signaler = new FakeProcessSignaler(); // dead

        $cmd = new AgentStopCommand(Console::getInstance(), $service, $signaler, 1);
        $cmd->handle([], ['code' => 'd01', 'cleanup' => true]);

        if ($service->has('d01')) {
            echo "FAIL testCleanupRemovesStaleSession: session not removed with --cleanup\n";
            $this->rmdir($dir);
            return 1;
        }
        if ($signaler->signals !== []) {
            echo "FAIL testCleanupRemovesStaleSession: no signal should be sent to a dead PID\n";
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
            clientPid: 1100,
            processGroupId: 1100,
        );
        $service->add($session);

        // Dead session, no --cleanup: stop refuses but should still refresh last_seen_at on the entry it inspected.
        $signaler = new FakeProcessSignaler();

        $cmd = new AgentStopCommand(Console::getInstance(), $service, $signaler, 1);
        try {
            $cmd->handle([], ['code' => 'd01']);
            echo "FAIL testUpdatesLastSeenOnObservation: expected refusal on dead PID without --cleanup\n";
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
     * @param int|null $clientPid
     * @param int|null $processGroupId
     */
    private function makeSession(string $code, ?int $clientPid = null, ?int $processGroupId = null): AgentSession
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
            clientPid: $clientPid,
            processGroupId: $processGroupId,
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
