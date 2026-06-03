<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Agent\Test;

use Sowapps\SoManAgent\Script\Backlog\Agent\Enum\AgentRole;
use Sowapps\SoManAgent\Script\Backlog\Agent\Enum\AgentClient;
use Sowapps\SoManAgent\Script\Backlog\Agent\Client\InteractiveProcessRunner;
use Sowapps\SoManAgent\Script\Backlog\Agent\Client\DirectSessionDriver;
use Sowapps\SoManAgent\Script\Console;
use Sowapps\SoManAgent\Script\Backlog\Agent\Model\AgentSession;
/**
 * Unit tests for DirectSessionDriver.
 *
 * Exercises dependency check (no-op), sessionExists (always false), stop (SIGTERM/SIGKILL),
 * isAlive (clientPid then pid), and the launch/resume delegation to InteractiveProcessRunner.
 */
final class DirectSessionDriverTest
{
    /**
     * Runs all test cases and returns the total number of failures.
     */
    public function run(): int
    {
        $failed = 0;

        $failed += $this->testCheckDependenciesIsNoOp();
        $failed += $this->testSessionExistsAlwaysFalse();
        $failed += $this->testDoesNotAllowResumeWhileAlive();
        $failed += $this->testIsAliveUsesClientPidFirst();
        $failed += $this->testIsAliveFallsBackToWrapperPid();
        $failed += $this->testIsAliveReturnsFalseWhenBothPidsDead();
        $failed += $this->testStopSendsTermToClientPid();
        $failed += $this->testStopSigkillFollowupWhenTermIgnored();
        $failed += $this->testStopFallsBackToWrapperPidWhenNoClientPid();
        $failed += $this->testStopWarnsWhenNoPidAvailable();
        $failed += $this->testLaunchDelegatesAndAdaptsOnSpawned();
        $failed += $this->testResumeDelegatesAndAdaptsOnSpawned();
        $failed += $this->testListLiveSessionsReturnsEmpty();
        $failed += $this->testKillIsNoOp();

        return $failed;
    }

    private function testCheckDependenciesIsNoOp(): int
    {
        $driver = $this->makeDriver(new FakeInteractiveProcessRunner(), new FakeProcessSignaler());

        try {
            $driver->checkDependencies();
            echo "OK testCheckDependenciesIsNoOp\n";
            return 0;
        } catch (\Throwable $e) {
            echo "FAIL testCheckDependenciesIsNoOp: unexpected exception: " . $e->getMessage() . "\n";
            return 1;
        }
    }

    private function testSessionExistsAlwaysFalse(): int
    {
        $driver = $this->makeDriver(new FakeInteractiveProcessRunner(), new FakeProcessSignaler());

        if ($driver->sessionExists('d01') !== false) {
            echo "FAIL testSessionExistsAlwaysFalse: expected false\n";
            return 1;
        }
        echo "OK testSessionExistsAlwaysFalse\n";
        return 0;
    }

    private function testDoesNotAllowResumeWhileAlive(): int
    {
        $driver = $this->makeDriver(new FakeInteractiveProcessRunner(), new FakeProcessSignaler());

        if ($driver->allowsResumeWhileAlive()) {
            echo "FAIL testDoesNotAllowResumeWhileAlive: expected false\n";
            return 1;
        }
        echo "OK testDoesNotAllowResumeWhileAlive\n";
        return 0;
    }

    private function testIsAliveUsesClientPidFirst(): int
    {
        $signaler = new FakeProcessSignaler();
        $signaler->setAlive(100, true); // clientPid alive
        $signaler->setAlive(200, false); // pid dead
        $driver = $this->makeDriver(new FakeInteractiveProcessRunner(), $signaler);
        $session = $this->makeSession('d01', clientPid: 100, pid: 200);

        if (!$driver->isAlive($session)) {
            echo "FAIL testIsAliveUsesClientPidFirst: expected alive because clientPid=100 is alive\n";
            return 1;
        }
        echo "OK testIsAliveUsesClientPidFirst\n";
        return 0;
    }

    private function testIsAliveFallsBackToWrapperPid(): int
    {
        $signaler = new FakeProcessSignaler();
        $signaler->setAlive(200, true); // pid alive
        $driver = $this->makeDriver(new FakeInteractiveProcessRunner(), $signaler);
        $session = $this->makeSession('d01', clientPid: null, pid: 200);

        if (!$driver->isAlive($session)) {
            echo "FAIL testIsAliveFallsBackToWrapperPid: expected alive because pid=200 is alive\n";
            return 1;
        }
        echo "OK testIsAliveFallsBackToWrapperPid\n";
        return 0;
    }

    private function testIsAliveReturnsFalseWhenBothPidsDead(): int
    {
        $signaler = new FakeProcessSignaler();
        $driver = $this->makeDriver(new FakeInteractiveProcessRunner(), $signaler);
        $session = $this->makeSession('d01', clientPid: 100, pid: 200);

        if ($driver->isAlive($session)) {
            echo "FAIL testIsAliveReturnsFalseWhenBothPidsDead: expected dead\n";
            return 1;
        }
        echo "OK testIsAliveReturnsFalseWhenBothPidsDead\n";
        return 0;
    }

    private function testStopSendsTermToClientPid(): int
    {
        $signaler = new FakeProcessSignaler();
        $signaler->setAlive(500, true);
        $signaler->sigtermKills = true;

        $driver = $this->makeDriver(new FakeInteractiveProcessRunner(), $signaler, graceSeconds: 1);
        $session = $this->makeSession('d01', clientPid: 500, pid: 100);

        $driver->stop($session);

        if ($signaler->signals === [] || $signaler->signals[0]['pid'] !== 500 || $signaler->signals[0]['signal'] !== SIGTERM) {
            echo "FAIL testStopSendsTermToClientPid: expected SIGTERM to 500, got " . var_export($signaler->signals, true) . "\n";
            return 1;
        }
        echo "OK testStopSendsTermToClientPid\n";
        return 0;
    }

    private function testStopSigkillFollowupWhenTermIgnored(): int
    {
        $signaler = new FakeProcessSignaler();
        $signaler->setAlive(600, true);
        $signaler->sigtermKills = false; // simulate stuck client

        $driver = $this->makeDriver(new FakeInteractiveProcessRunner(), $signaler, graceSeconds: 0);
        $session = $this->makeSession('d01', clientPid: 600);

        $driver->stop($session);

        $signals = array_column($signaler->signals, 'signal');
        if (!in_array(SIGTERM, $signals, true) || !in_array(SIGKILL, $signals, true)) {
            echo "FAIL testStopSigkillFollowupWhenTermIgnored: expected SIGTERM then SIGKILL\n";
            return 1;
        }
        echo "OK testStopSigkillFollowupWhenTermIgnored\n";
        return 0;
    }

    private function testStopFallsBackToWrapperPidWhenNoClientPid(): int
    {
        $signaler = new FakeProcessSignaler();
        $signaler->setAlive(700, true);

        $driver = $this->makeDriver(new FakeInteractiveProcessRunner(), $signaler, graceSeconds: 1);
        $session = $this->makeSession('d01', clientPid: null, pid: 700);

        $driver->stop($session);

        if ($signaler->signals === [] || $signaler->signals[0]['pid'] !== 700) {
            echo "FAIL testStopFallsBackToWrapperPidWhenNoClientPid: expected signal to PID 700\n";
            return 1;
        }
        echo "OK testStopFallsBackToWrapperPidWhenNoClientPid\n";
        return 0;
    }

    private function testStopWarnsWhenNoPidAvailable(): int
    {
        $signaler = new FakeProcessSignaler();
        $driver = $this->makeDriver(new FakeInteractiveProcessRunner(), $signaler);
        $session = $this->makeSession('d01', clientPid: null, pid: 0);

        ob_start();
        $driver->stop($session);
        ob_end_clean();

        if ($signaler->signals !== []) {
            echo "FAIL testStopWarnsWhenNoPidAvailable: expected no signals when no PID is available\n";
            return 1;
        }
        echo "OK testStopWarnsWhenNoPidAvailable\n";
        return 0;
    }

    private function testLaunchDelegatesAndAdaptsOnSpawned(): int
    {
        $runner = new FakeInteractiveProcessRunner();
        $runner->nextClientPid = 999;

        $driver = $this->makeDriver($runner, new FakeProcessSignaler());

        $capturedPid = null;
        $capturedTmux = 'sentinel';

        $exitCode = $driver->launch('d01', AgentRole::DEVELOPER, AgentClient::CLAUDE, '/bin/sh', ['-c', 'true'], sys_get_temp_dir(), [], static function (int $pid, ?string $tmux) use (&$capturedPid, &$capturedTmux): void {
            $capturedPid = $pid;
            $capturedTmux = $tmux;
        });

        if ($exitCode !== 0) {
            echo "FAIL testLaunchDelegatesAndAdaptsOnSpawned: expected exit 0, got {$exitCode}\n";
            return 1;
        }
        if ($capturedPid !== 999) {
            echo "FAIL testLaunchDelegatesAndAdaptsOnSpawned: expected clientPid 999, got {$capturedPid}\n";
            return 1;
        }
        if ($capturedTmux !== null) {
            echo "FAIL testLaunchDelegatesAndAdaptsOnSpawned: expected tmuxSession null for direct driver, got '{$capturedTmux}'\n";
            return 1;
        }
        if ($runner->lastCall === null) {
            echo "FAIL testLaunchDelegatesAndAdaptsOnSpawned: runner was not called\n";
            return 1;
        }
        echo "OK testLaunchDelegatesAndAdaptsOnSpawned\n";
        return 0;
    }

    private function testResumeDelegatesAndAdaptsOnSpawned(): int
    {
        $runner = new FakeInteractiveProcessRunner();
        $runner->nextClientPid = 888;

        $driver = $this->makeDriver($runner, new FakeProcessSignaler());

        $capturedTmux = 'sentinel';

        $driver->resume('d01', AgentRole::DEVELOPER, AgentClient::CLAUDE, '/bin/sh', ['-c', 'true'], sys_get_temp_dir(), [], static function (int $pid, ?string $tmux) use (&$capturedTmux): void {
            $capturedTmux = $tmux;
        });

        if ($capturedTmux !== null) {
            echo "FAIL testResumeDelegatesAndAdaptsOnSpawned: expected null tmuxSession for direct driver, got '{$capturedTmux}'\n";
            return 1;
        }
        if ($runner->lastCall === null) {
            echo "FAIL testResumeDelegatesAndAdaptsOnSpawned: runner was not called\n";
            return 1;
        }
        echo "OK testResumeDelegatesAndAdaptsOnSpawned\n";
        return 0;
    }

    private function testListLiveSessionsReturnsEmpty(): int
    {
        $driver = $this->makeDriver(new FakeInteractiveProcessRunner(), new FakeProcessSignaler());

        if ($driver->listLiveSessions() !== []) {
            echo "FAIL testListLiveSessionsReturnsEmpty: expected empty array for direct driver\n";
            return 1;
        }
        echo "OK testListLiveSessionsReturnsEmpty\n";
        return 0;
    }

    private function testKillIsNoOp(): int
    {
        $driver = $this->makeDriver(new FakeInteractiveProcessRunner(), new FakeProcessSignaler());

        try {
            $driver->kill('d01');
            echo "OK testKillIsNoOp\n";
            return 0;
        } catch (\Throwable $e) {
            echo "FAIL testKillIsNoOp: unexpected exception: " . $e->getMessage() . "\n";
            return 1;
        }
    }

    private function makeDriver(
        InteractiveProcessRunner $runner,
        FakeProcessSignaler $signaler,
        int $graceSeconds = DirectSessionDriver::DEFAULT_TERMINATION_GRACE_SECONDS,
    ): DirectSessionDriver {
        return new DirectSessionDriver($runner, $signaler, Console::getInstance(), $graceSeconds);
    }

    private function makeSession(string $code, ?int $clientPid = null, int $pid = 42): AgentSession
    {
        $now = new \DateTimeImmutable();
        return new AgentSession(
            code: $code,
            client: AgentClient::CLAUDE,
            role: AgentRole::DEVELOPER,
            pid: $pid,
            worktree: '/tmp/fake',
            startedAt: $now,
            lastSeenAt: $now,
            sessionId: null,
            clientPid: $clientPid,
        );
    }
}
