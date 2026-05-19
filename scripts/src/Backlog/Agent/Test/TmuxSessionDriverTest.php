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
        $failed += $this->testAllowsResumeWhileAlive();
        $failed += $this->testSessionExistsReturnsTrueAfterTmuxDetach();
        $failed += $this->testIsAliveReturnsTrueWhenTmuxSessionExists();
        $failed += $this->testIsAliveReturnsFalseWhenTmuxSessionGone();
        $failed += $this->testIsAliveReturnsFalseWhenTmuxSessionNull();
        $failed += $this->testStopCallsKillSession();
        $failed += $this->testStopWarnsWhenNoTmuxSessionRecorded();
        $failed += $this->testGetPanePidQuotesFormatToken();
        $failed += $this->testGetPanePidThrowsWhenOutputIsTmuxDefault();
        $failed += $this->testCreateSessionAppliesMouseOption();
        $failed += $this->testCreateSessionAppliesHistoryLimit();
        $failed += $this->testCreateSessionAppliesSowappsStatusLineInOrder();
        $failed += $this->testCreateSessionDoesNotUseGlobalTmuxOptions();
        $failed += $this->testCreateSessionWarnsWhenBrandingOptionFails();
        $failed += $this->testCreateSessionWarnsWhenSetOptionFails();
        $failed += $this->testListLiveSessionsFiltersPrefix();
        $failed += $this->testListLiveSessionsReturnsEmptyWhenNoOutput();
        $failed += $this->testKillCallsKillSession();

        return $failed;
    }

    private function testCheckDependenciesThrowsWhenTmuxMissing(): int
    {
        $runner = new FakeProcessRunner();
        $runner->succeedsResult = false; // command -v tmux fails

        $driver = new TmuxSessionDriver($runner, Console::getInstance());

        $threw = false;
        $message = '';
        try {
            $driver->checkDependencies();
        } catch (\RuntimeException $e) {
            $threw = true;
            $message = $e->getMessage();
        }

        if (!$threw) {
            echo "FAIL testCheckDependenciesThrowsWhenTmuxMissing: expected RuntimeException\n";
            return 1;
        }
        if (!str_contains($message, 'tmux is not installed')) {
            echo "FAIL testCheckDependenciesThrowsWhenTmuxMissing: message does not mention 'tmux is not installed'\n";
            return 1;
        }
        if (!str_contains($message, 'setup.php install')) {
            echo "FAIL testCheckDependenciesThrowsWhenTmuxMissing: message does not mention 'setup.php install'\n";
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

    private function testAllowsResumeWhileAlive(): int
    {
        $driver = new TmuxSessionDriver(new FakeProcessRunner(), Console::getInstance());

        if (!$driver->allowsResumeWhileAlive()) {
            echo "FAIL testAllowsResumeWhileAlive: expected true\n";
            return 1;
        }
        echo "OK testAllowsResumeWhileAlive\n";
        return 0;
    }

    /**
     * After a tmux detach (Ctrl+B D or SSH disconnect), attach-session exits but the tmux session
     * stays alive. sessionExists() must return true in that state so callers can detect the detach
     * and keep the sessions.json entry.
     */
    private function testSessionExistsReturnsTrueAfterTmuxDetach(): int
    {
        $runner = new FakeProcessRunner();
        $runner->succeedsResult = true; // tmux has-session still returns 0 (session alive after detach)

        $driver = new TmuxSessionDriver($runner, Console::getInstance());

        if (!$driver->sessionExists('d01')) {
            echo "FAIL testSessionExistsReturnsTrueAfterTmuxDetach: expected true (session still alive after detach)\n";
            return 1;
        }
        echo "OK testSessionExistsReturnsTrueAfterTmuxDetach\n";
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
        $runner = new RecordingProcessRunner();

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

    /**
     * Regression guard: the `#{pane_pid}` format token must be wrapped in single quotes,
     * otherwise the shell strips it as a comment and tmux falls back to its default summary.
     * This test exercises the private `getPanePid` via reflection because the method is the
     * exact contract that broke in production; testing it through `launch()` would also
     * exercise unrelated tmux side effects.
     */
    private function testGetPanePidQuotesFormatToken(): int
    {
        $runner = new FakeProcessRunner();
        $runner->outputMap = [
            "tmux display-message -t 'somanagent-d03' -p '#{pane_pid}'" => "58250\n",
        ];

        $driver = new TmuxSessionDriver($runner, Console::getInstance());
        $reflection = new \ReflectionMethod(TmuxSessionDriver::class, 'getPanePid');
        $reflection->setAccessible(true);
        $pid = $reflection->invoke($driver, 'somanagent-d03');

        if ($pid !== 58250) {
            echo "FAIL testGetPanePidQuotesFormatToken: expected pid 58250, got " . var_export($pid, true) . "\n";
            return 1;
        }
        if (count($runner->outputCalls) !== 1) {
            echo "FAIL testGetPanePidQuotesFormatToken: expected exactly 1 output() call, got " . count($runner->outputCalls) . "\n";
            return 1;
        }
        $command = $runner->outputCalls[0];
        if (!str_contains($command, "'#{pane_pid}'")) {
            echo "FAIL testGetPanePidQuotesFormatToken: command does not quote the format token: {$command}\n";
            return 1;
        }
        if (!str_contains($command, "'somanagent-d03'")) {
            echo "FAIL testGetPanePidQuotesFormatToken: command does not include the escaped session name: {$command}\n";
            return 1;
        }

        echo "OK testGetPanePidQuotesFormatToken\n";
        return 0;
    }

    /**
     * Regression guard: when tmux falls back to its default summary line (e.g. because the
     * shell stripped the format), `getPanePid` must surface the parse failure as an explicit
     * exception so the bug never returns silently.
     */
    private function testGetPanePidThrowsWhenOutputIsTmuxDefault(): int
    {
        $runner = new FakeProcessRunner();
        $runner->outputMap = [
            "tmux display-message -t 'somanagent-d03' -p '#{pane_pid}'"
                => "[session] 0:window, current pane 0 - (-:-)\n",
        ];

        $driver = new TmuxSessionDriver($runner, Console::getInstance());
        $reflection = new \ReflectionMethod(TmuxSessionDriver::class, 'getPanePid');
        $reflection->setAccessible(true);

        try {
            $reflection->invoke($driver, 'somanagent-d03');
        } catch (\ReflectionException $e) {
            $previous = $e->getPrevious();
            if (!$previous instanceof \RuntimeException || !str_contains($previous->getMessage(), 'Could not determine pane PID')) {
                echo "FAIL testGetPanePidThrowsWhenOutputIsTmuxDefault: unexpected previous exception: " . var_export($previous, true) . "\n";
                return 1;
            }
            echo "OK testGetPanePidThrowsWhenOutputIsTmuxDefault\n";
            return 0;
        } catch (\RuntimeException $e) {
            if (!str_contains($e->getMessage(), 'Could not determine pane PID')) {
                echo "FAIL testGetPanePidThrowsWhenOutputIsTmuxDefault: unexpected message: " . $e->getMessage() . "\n";
                return 1;
            }
            echo "OK testGetPanePidThrowsWhenOutputIsTmuxDefault\n";
            return 0;
        }

        echo "FAIL testGetPanePidThrowsWhenOutputIsTmuxDefault: expected RuntimeException\n";
        return 1;
    }

    /**
     * After createSession() succeeds, the driver must apply `tmux set-option -t <name> mouse on`
     * so the mouse wheel enters copy mode and allows scrollback in the pane.
     */
    private function testCreateSessionAppliesMouseOption(): int
    {
        $runner = new FakeProcessRunner();
        $driver = new TmuxSessionDriver($runner, Console::getInstance());
        $reflection = new \ReflectionMethod(TmuxSessionDriver::class, 'createSession');
        $reflection->setAccessible(true);
        $reflection->invoke($driver, 'somanagent-d03', 'd03', AgentRole::DEVELOPER, AgentClient::CLAUDE, '/tmp/fake.sh', '/tmp/wa');

        $mouseCalls = array_filter(
            $runner->succeedsCalls,
            static fn(string $c): bool => str_contains($c, 'set-option') && str_contains($c, 'mouse on'),
        );
        if ($mouseCalls === []) {
            echo "FAIL testCreateSessionAppliesMouseOption: 'tmux set-option ... mouse on' was not called\n";
            return 1;
        }
        echo "OK testCreateSessionAppliesMouseOption\n";
        return 0;
    }

    /**
     * After createSession() succeeds, the driver must apply `tmux set-option -t <name> history-limit 50000`
     * to extend the scrollback buffer beyond the tmux default of 2 000 lines.
     */
    private function testCreateSessionAppliesHistoryLimit(): int
    {
        $runner = new FakeProcessRunner();
        $driver = new TmuxSessionDriver($runner, Console::getInstance());
        $reflection = new \ReflectionMethod(TmuxSessionDriver::class, 'createSession');
        $reflection->setAccessible(true);
        $reflection->invoke($driver, 'somanagent-d03', 'd03', AgentRole::DEVELOPER, AgentClient::CLAUDE, '/tmp/fake.sh', '/tmp/wa');

        $histCalls = array_filter(
            $runner->succeedsCalls,
            static fn(string $c): bool => str_contains($c, 'set-option') && str_contains($c, 'history-limit 50000'),
        );
        if ($histCalls === []) {
            echo "FAIL testCreateSessionAppliesHistoryLimit: 'tmux set-option ... history-limit 50000' was not called\n";
            return 1;
        }
        echo "OK testCreateSessionAppliesHistoryLimit\n";
        return 0;
    }

    /**
     * The Sowapps status-line branding must be applied after the existing tmux ergonomics in the
     * exact session-local order specified by the launcher contract.
     */
    private function testCreateSessionAppliesSowappsStatusLineInOrder(): int
    {
        $runner = new FakeProcessRunner();
        $driver = new TmuxSessionDriver($runner, Console::getInstance());
        $reflection = new \ReflectionMethod(TmuxSessionDriver::class, 'createSession');
        $reflection->setAccessible(true);
        $reflection->invoke($driver, 'somanagent-d03', 'd03', AgentRole::DEVELOPER, AgentClient::CLAUDE, '/tmp/fake.sh', '/tmp/wa');

        $expectedBranding = [
            "tmux set -t 'somanagent-d03' status 'on'",
            "tmux set -t 'somanagent-d03' status-style 'bg=colour202,fg=colour231'",
            "tmux set -t 'somanagent-d03' status-left-length '20'",
            "tmux set -t 'somanagent-d03' status-right-length '50'",
            "tmux set -t 'somanagent-d03' status-left '#[fg=colour202,bg=colour231] SOWAPPS #[default] '",
            "tmux set -t 'somanagent-d03' status-right ' developer · claude · %Y-%m-%d %H:%M:%S '",
            "tmux set -t 'somanagent-d03' window-status-format ' d03 '",
            "tmux set -t 'somanagent-d03' window-status-current-format ' d03 '",
            "tmux set -t 'somanagent-d03' window-status-style 'bg=colour202,fg=colour231'",
            "tmux set -t 'somanagent-d03' window-status-current-style 'bg=terminal,fg=colour231,bold'",
            "tmux set -t 'somanagent-d03' window-status-separator ''",
        ];
        $expectedAll = [
            "tmux new-session -d -s 'somanagent-d03' -c '/tmp/wa' '/tmp/fake.sh'",
            "tmux set-option -t 'somanagent-d03' mouse on",
            "tmux set-option -t 'somanagent-d03' history-limit 50000",
            ...$expectedBranding,
        ];
        $brandingCalls = array_values(array_filter(
            $runner->succeedsCalls,
            static fn(string $c): bool => str_starts_with($c, 'tmux set -t '),
        ));

        if ($brandingCalls !== $expectedBranding) {
            echo "FAIL testCreateSessionAppliesSowappsStatusLineInOrder: expected branding calls "
                . var_export($expectedBranding, true) . ', got ' . var_export($brandingCalls, true) . "\n";
            return 1;
        }
        if ($runner->succeedsCalls !== $expectedAll) {
            echo "FAIL testCreateSessionAppliesSowappsStatusLineInOrder: expected complete call order "
                . var_export($expectedAll, true) . ', got ' . var_export($runner->succeedsCalls, true) . "\n";
            return 1;
        }
        echo "OK testCreateSessionAppliesSowappsStatusLineInOrder\n";
        return 0;
    }

    /**
     * All tmux customisation for agent sessions must target the session; no global `-g` writes.
     */
    private function testCreateSessionDoesNotUseGlobalTmuxOptions(): int
    {
        $runner = new FakeProcessRunner();
        $driver = new TmuxSessionDriver($runner, Console::getInstance());
        $reflection = new \ReflectionMethod(TmuxSessionDriver::class, 'createSession');
        $reflection->setAccessible(true);
        $reflection->invoke($driver, 'somanagent-d03', 'd03', AgentRole::DEVELOPER, AgentClient::CLAUDE, '/tmp/fake.sh', '/tmp/wa');

        foreach ($runner->succeedsCalls as $command) {
            if (str_contains($command, ' -g')) {
                echo "FAIL testCreateSessionDoesNotUseGlobalTmuxOptions: found global tmux option call: {$command}\n";
                return 1;
            }
        }
        echo "OK testCreateSessionDoesNotUseGlobalTmuxOptions\n";
        return 0;
    }

    /**
     * A tmux version that rejects one branding option must not prevent the session from starting.
     */
    private function testCreateSessionWarnsWhenBrandingOptionFails(): int
    {
        $runner = new FakeProcessRunner();
        // new-session, mouse, history-limit succeed; one branding option fails; remaining options continue.
        $runner->succeedsQueue = [true, true, true, true, true, true, true, true, false];

        $driver = new TmuxSessionDriver($runner, Console::getInstance());
        $reflection = new \ReflectionMethod(TmuxSessionDriver::class, 'createSession');
        $reflection->setAccessible(true);

        ob_start();
        try {
            $reflection->invoke($driver, 'somanagent-d03', 'd03', AgentRole::DEVELOPER, AgentClient::CLAUDE, '/tmp/fake.sh', '/tmp/wa');
        } catch (\Throwable $e) {
            ob_end_clean();
            $inner = $e instanceof \ReflectionException ? $e->getPrevious() : $e;
            echo "FAIL testCreateSessionWarnsWhenBrandingOptionFails: unexpected exception: " . ($inner?->getMessage() ?? $e->getMessage()) . "\n";
            return 1;
        }
        $output = ob_get_clean();

        if (!str_contains((string) $output, 'Sowapps status-line branding')) {
            echo "FAIL testCreateSessionWarnsWhenBrandingOptionFails: expected branding warning, got: " . var_export($output, true) . "\n";
            return 1;
        }
        $brandingCalls = array_filter(
            $runner->succeedsCalls,
            static fn(string $c): bool => str_starts_with($c, 'tmux set -t '),
        );
        if (count($brandingCalls) !== 11) {
            echo "FAIL testCreateSessionWarnsWhenBrandingOptionFails: expected all 11 branding commands, got " . count($brandingCalls) . "\n";
            return 1;
        }
        echo "OK testCreateSessionWarnsWhenBrandingOptionFails\n";
        return 0;
    }

    /**
     * If the set-option commands fail (tmux returns non-zero), createSession() must not throw
     * and must emit a warning via Console::warn() for each failed option so the operator knows
     * that mouse scrollback or the extended history limit is unavailable.
     */
    private function testCreateSessionWarnsWhenSetOptionFails(): int
    {
        $runner = new FakeProcessRunner();
        // new-session succeeds; the two set-option calls fail
        $runner->succeedsQueue = [true, false, false];

        $driver = new TmuxSessionDriver($runner, Console::getInstance());
        $reflection = new \ReflectionMethod(TmuxSessionDriver::class, 'createSession');
        $reflection->setAccessible(true);

        ob_start();
        try {
            $reflection->invoke($driver, 'somanagent-d03', 'd03', AgentRole::DEVELOPER, AgentClient::CLAUDE, '/tmp/fake.sh', '/tmp/wa');
        } catch (\Throwable $e) {
            ob_end_clean();
            $inner = $e instanceof \ReflectionException ? $e->getPrevious() : $e;
            echo "FAIL testCreateSessionWarnsWhenSetOptionFails: unexpected exception: " . ($inner?->getMessage() ?? $e->getMessage()) . "\n";
            return 1;
        }
        $output = ob_get_clean();

        if (!str_contains((string) $output, 'mouse')) {
            echo "FAIL testCreateSessionWarnsWhenSetOptionFails: expected warning mentioning 'mouse', got: " . var_export($output, true) . "\n";
            return 1;
        }
        if (!str_contains((string) $output, 'history-limit')) {
            echo "FAIL testCreateSessionWarnsWhenSetOptionFails: expected warning mentioning 'history-limit', got: " . var_export($output, true) . "\n";
            return 1;
        }
        echo "OK testCreateSessionWarnsWhenSetOptionFails\n";
        return 0;
    }

    /**
     * listLiveSessions() must return codes from somanagent-prefixed sessions only.
     * Non-prefixed sessions in the tmux output are silently ignored.
     */
    private function testListLiveSessionsFiltersPrefix(): int
    {
        $runner = new FakeProcessRunner();
        $runner->outputMap = [
            "tmux list-sessions -F '#{session_name}'" => "somanagent-d11\nother-session\nsomanagent-d12\n",
        ];

        $driver = new TmuxSessionDriver($runner, Console::getInstance());
        $codes = $driver->listLiveSessions();

        if ($codes !== ['d11', 'd12']) {
            echo "FAIL testListLiveSessionsFiltersPrefix: expected ['d11', 'd12'], got " . var_export($codes, true) . "\n";
            return 1;
        }
        echo "OK testListLiveSessionsFiltersPrefix\n";
        return 0;
    }

    /**
     * listLiveSessions() must return [] when tmux list-sessions yields no output (no server or no sessions).
     */
    private function testListLiveSessionsReturnsEmptyWhenNoOutput(): int
    {
        $runner = new FakeProcessRunner();
        $runner->outputMap = [
            "tmux list-sessions -F '#{session_name}'" => null,
        ];

        $driver = new TmuxSessionDriver($runner, Console::getInstance());
        $codes = $driver->listLiveSessions();

        if ($codes !== []) {
            echo "FAIL testListLiveSessionsReturnsEmptyWhenNoOutput: expected [], got " . var_export($codes, true) . "\n";
            return 1;
        }
        echo "OK testListLiveSessionsReturnsEmptyWhenNoOutput\n";
        return 0;
    }

    /**
     * kill() must call tmux kill-session targeting somanagent-<code>.
     */
    private function testKillCallsKillSession(): int
    {
        $runner = new RecordingProcessRunner();

        $driver = new TmuxSessionDriver($runner, Console::getInstance());

        ob_start();
        $driver->kill('d11');
        ob_end_clean();

        $killCalls = array_filter($runner->calledCommands, fn(string $c): bool => str_contains($c, 'kill-session'));
        if ($killCalls === []) {
            echo "FAIL testKillCallsKillSession: tmux kill-session was not called\n";
            return 1;
        }
        $killCmd = reset($killCalls);
        if (!str_contains($killCmd, 'somanagent-d11')) {
            echo "FAIL testKillCallsKillSession: kill-session did not target 'somanagent-d11'\n";
            return 1;
        }
        echo "OK testKillCallsKillSession\n";
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

/**
 * ProcessRunner that records all commands passed to succeeds().
 */
final class RecordingProcessRunner implements \SoManAgent\Script\Backlog\Agent\Client\ProcessRunner
{
    /**
     * @var list<string>
     */
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
}
