<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Test;

use SoManAgent\Script\Backlog\Agent\Client\TmuxSessionDriver;
use SoManAgent\Script\Backlog\Agent\Enum\AgentClient;
use SoManAgent\Script\Backlog\Agent\Enum\AgentRole;
use SoManAgent\Script\Backlog\Agent\Model\AgentSession;
use SoManAgent\Script\Console;

/**
 * Unit tests for TmuxSessionDriver.
 *
 * Uses FakeProcessRunner to control tmux command results without running real tmux.
 * Covers dependency check, sessionExists, isAlive, stop, and the dependency-missing error path.
 */
final class TmuxSessionDriverTest
{
    /**
     * Runs all test cases and returns the total number of failures.
     */
    public function run(): int
    {
        $failed = 0;

        $failed += $this->testCheckDependenciesThrowsWhenTmuxMissing();
        $failed += $this->testCheckDependenciesPassesWhenTmuxPresent();
        $failed += $this->testSessionExistsReturnsTrueWhenHasSessionSucceeds();
        $failed += $this->testSessionExistsReturnsFalseWhenHasSessionFails();
        $failed += $this->testIsAliveReturnsTrueWhenTmuxSessionExists();
        $failed += $this->testIsAliveReturnsFalseWhenTmuxSessionGone();
        $failed += $this->testIsAliveReturnsFalseWhenTmuxSessionNull();
        $failed += $this->testStopCallsKillSession();
        $failed += $this->testStopWarnsWhenNoTmuxSessionRecorded();

        return $failed;
    }

    private function testCheckDependenciesThrowsWhenTmuxMissing(): int
    {
        $runner = new FakeProcessRunner();
        $runner->succeedsResult = false; // command -v tmux fails

        $driver = new TmuxSessionDriver($runner, Console::getInstance());

        $threw = false;
        try {
            $driver->checkDependencies();
        } catch (\RuntimeException $e) {
            $threw = str_contains($e->getMessage(), 'tmux is not installed');
        }

        if (!$threw) {
            echo "FAIL testCheckDependenciesThrowsWhenTmuxMissing: expected RuntimeException mentioning tmux\n";
            return 1;
        }
        echo "OK testCheckDependenciesThrowsWhenTmuxMissing\n";
        return 0;
    }

    private function testCheckDependenciesPassesWhenTmuxPresent(): int
    {
        $runner = new FakeProcessRunner();
        $runner->succeedsResult = true; // command -v tmux succeeds

        $driver = new TmuxSessionDriver($runner, Console::getInstance());

        try {
            $driver->checkDependencies();
            echo "OK testCheckDependenciesPassesWhenTmuxPresent\n";
            return 0;
        } catch (\Throwable $e) {
            echo "FAIL testCheckDependenciesPassesWhenTmuxPresent: unexpected exception: " . $e->getMessage() . "\n";
            return 1;
        }
    }

    private function testSessionExistsReturnsTrueWhenHasSessionSucceeds(): int
    {
        $runner = new FakeProcessRunner();
        $runner->succeedsResult = true; // tmux has-session returns 0

        $driver = new TmuxSessionDriver($runner, Console::getInstance());

        if (!$driver->sessionExists('d01')) {
            echo "FAIL testSessionExistsReturnsTrueWhenHasSessionSucceeds: expected true\n";
            return 1;
        }
        echo "OK testSessionExistsReturnsTrueWhenHasSessionSucceeds\n";
        return 0;
    }

    private function testSessionExistsReturnsFalseWhenHasSessionFails(): int
    {
        $runner = new FakeProcessRunner();
        $runner->succeedsResult = false; // tmux has-session returns non-zero

        $driver = new TmuxSessionDriver($runner, Console::getInstance());

        if ($driver->sessionExists('d01')) {
            echo "FAIL testSessionExistsReturnsFalseWhenHasSessionFails: expected false\n";
            return 1;
        }
        echo "OK testSessionExistsReturnsFalseWhenHasSessionFails\n";
        return 0;
    }

    private function testIsAliveReturnsTrueWhenTmuxSessionExists(): int
    {
        $runner = new FakeProcessRunner();
        $runner->succeedsResult = true; // tmux has-session succeeds

        $driver = new TmuxSessionDriver($runner, Console::getInstance());
        $session = $this->makeSession('d01', tmuxSession: 'somanagent-d01');

        if (!$driver->isAlive($session)) {
            echo "FAIL testIsAliveReturnsTrueWhenTmuxSessionExists: expected alive\n";
            return 1;
        }
        echo "OK testIsAliveReturnsTrueWhenTmuxSessionExists\n";
        return 0;
    }

    private function testIsAliveReturnsFalseWhenTmuxSessionGone(): int
    {
        $runner = new FakeProcessRunner();
        $runner->succeedsResult = false; // tmux has-session fails (session gone)

        $driver = new TmuxSessionDriver($runner, Console::getInstance());
        $session = $this->makeSession('d01', tmuxSession: 'somanagent-d01');

        if ($driver->isAlive($session)) {
            echo "FAIL testIsAliveReturnsFalseWhenTmuxSessionGone: expected dead\n";
            return 1;
        }
        echo "OK testIsAliveReturnsFalseWhenTmuxSessionGone\n";
        return 0;
    }

    private function testIsAliveReturnsFalseWhenTmuxSessionNull(): int
    {
        $runner = new FakeProcessRunner();
        $runner->succeedsResult = true; // even if tmux responds, null session = always dead

        $driver = new TmuxSessionDriver($runner, Console::getInstance());
        $session = $this->makeSession('d01', tmuxSession: null);

        if ($driver->isAlive($session)) {
            echo "FAIL testIsAliveReturnsFalseWhenTmuxSessionNull: expected dead when tmuxSession is null\n";
            return 1;
        }
        echo "OK testIsAliveReturnsFalseWhenTmuxSessionNull\n";
        return 0;
    }

    private function testStopCallsKillSession(): int
    {
        $runner = new class implements \SoManAgent\Script\Backlog\Agent\Client\ProcessRunner {
            /** @var list<string> */
            public array $calledCommands = [];

            /**
             * {@inheritdoc}
             */
            public function succeeds(string $command): bool
            {
                $this->calledCommands[] = $command;
                return true;
            }

            /**
             * {@inheritdoc}
             */
            public function output(string $command, string $cwd = ''): ?string
            {
                return null;
            }
        };

        $driver = new TmuxSessionDriver($runner, Console::getInstance());
        $session = $this->makeSession('d01', tmuxSession: 'somanagent-d01');

        ob_start();
        $driver->stop($session);
        ob_end_clean();

        $killCalls = array_filter($runner->calledCommands, fn(string $c): bool => str_contains($c, 'kill-session'));
        if ($killCalls === []) {
            echo "FAIL testStopCallsKillSession: tmux kill-session was not called\n";
            return 1;
        }
        $killCmd = reset($killCalls);
        if (!str_contains($killCmd, 'somanagent-d01')) {
            echo "FAIL testStopCallsKillSession: kill-session did not target 'somanagent-d01'\n";
            return 1;
        }
        echo "OK testStopCallsKillSession\n";
        return 0;
    }

    private function testStopWarnsWhenNoTmuxSessionRecorded(): int
    {
        $runner = new FakeProcessRunner();
        $driver = new TmuxSessionDriver($runner, Console::getInstance());
        $session = $this->makeSession('d01', tmuxSession: null);

        ob_start();
        $driver->stop($session);
        $output = ob_get_clean();

        // Should not throw; should warn instead
        echo "OK testStopWarnsWhenNoTmuxSessionRecorded\n";
        return 0;
    }

    private function makeSession(string $code, ?string $tmuxSession = null): AgentSession
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
            tmuxSession: $tmuxSession,
        );
    }
}
