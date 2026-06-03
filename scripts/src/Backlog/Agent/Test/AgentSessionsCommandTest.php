<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Agent\Test;

use Sowapps\SoManAgent\Script\Backlog\Agent\Service\AgentSessionService;
use Sowapps\SoManAgent\Script\Backlog\Agent\Enum\AgentClient;
use Sowapps\SoManAgent\Script\Backlog\Agent\Model\SessionInfo;
use Sowapps\SoManAgent\Script\Backlog\Agent\Model\AgentSession;
use Sowapps\SoManAgent\Script\Backlog\Agent\Enum\AgentRole;
use Sowapps\SoManAgent\Script\Backlog\Agent\Client\AgentClientLauncher;
use Sowapps\SoManAgent\Script\Backlog\Agent\Command\AgentSessionsCommand;
use Sowapps\SoManAgent\Script\Backlog\Agent\Client\AgentClientLauncherRegistry;
use Sowapps\SoManAgent\Script\Console;
use Sowapps\SoManAgent\Script\Backlog\Agent\Test\FakeAgentClientLauncher;
/**
 * Command-level tests for {@see AgentSessionsCommand}.
 *
 * The launcher is faked by {@see FakeAgentClientLauncher} so no real claude/codex/etc.
 * client is ever invoked. The session worktree is a temp directory so the missing-WA
 * branch can be exercised by skipping the mkdir.
 */
final class AgentSessionsCommandTest
{
    private string $tmpDir;

    /**
     * Creates temp directory for test fixtures.
     */
    public function __construct()
    {
        $this->tmpDir = sys_get_temp_dir() . '/backlog-agent-sessions-test-' . uniqid('', true);
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

        $failed += $this->testRefusesWhenCodeMissing();
        $failed += $this->testRefusesWhenWorktreeMissing();
        $failed += $this->testRefusesWhenNoActiveSession();
        $failed += $this->testPrintsNoPastSessions();
        $failed += $this->testRendersTableFromLauncherSessions();
        $failed += $this->testRefreshesLastSeenWhenSessionPresent();

        return $failed;
    }

    private function testRefusesWhenCodeMissing(): int
    {
        $service = new AgentSessionService($this->scratch('missing-code'));
        $cmd = $this->buildCommand($service, new FakeAgentClientLauncher(AgentClient::CLAUDE, []));

        $threw = false;
        try {
            $cmd->handle([], []);
        } catch (\RuntimeException $e) {
            $threw = str_contains($e->getMessage(), '--code=<code> is required');
        }

        if (!$threw) {
            echo "FAIL testRefusesWhenCodeMissing: expected explicit --code requirement error\n";
            return 1;
        }
        echo "OK testRefusesWhenCodeMissing\n";
        return 0;
    }

    private function testRefusesWhenWorktreeMissing(): int
    {
        $dir = $this->scratch('missing-wa');
        $service = new AgentSessionService($dir);
        $missingWa = $dir . '/wa-does-not-exist';
        $service->add($this->makeSession('d01', $missingWa));

        $cmd = $this->buildCommand($service, new FakeAgentClientLauncher(AgentClient::CLAUDE, []));

        $threw = false;
        try {
            $cmd->handle([], ['code' => 'd01']);
        } catch (\RuntimeException $e) {
            $threw = str_contains($e->getMessage(), "Worktree not found for code 'd01'");
        }
        if (!$threw) {
            echo "FAIL testRefusesWhenWorktreeMissing: expected explicit worktree-missing error\n";
            return 1;
        }
        echo "OK testRefusesWhenWorktreeMissing\n";
        return 0;
    }

    private function testRefusesWhenNoActiveSession(): int
    {
        $dir = $this->scratch('no-session');
        $service = new AgentSessionService($dir);

        // Worktree exists but there is no recorded session: the command cannot know which client to query.
        $wa = $dir . '/wa-d99';
        mkdir($wa, 0755, true);

        $cmd = $this->buildCommand($service, new FakeAgentClientLauncher(AgentClient::CLAUDE, []));

        $threw = false;
        try {
            $cmd->handle([], ['code' => 'd99']);
        } catch (\RuntimeException $e) {
            $threw = str_contains($e->getMessage(), "No active session for code 'd99'")
                || str_contains($e->getMessage(), "Worktree not found for code 'd99'");
        }
        if (!$threw) {
            echo "FAIL testRefusesWhenNoActiveSession: expected explicit error about missing session\n";
            return 1;
        }
        echo "OK testRefusesWhenNoActiveSession\n";
        return 0;
    }

    private function testPrintsNoPastSessions(): int
    {
        $dir = $this->scratch('no-past');
        $service = new AgentSessionService($dir);
        $wa = $dir . '/wa-d01';
        mkdir($wa, 0755, true);
        $service->add($this->makeSession('d01', $wa));

        $cmd = $this->buildCommand($service, new FakeAgentClientLauncher(AgentClient::CLAUDE, []));

        $output = $this->captureHandle($cmd, ['code' => 'd01']);

        if (!str_contains($output, 'No past sessions found for d01.')) {
            echo "FAIL testPrintsNoPastSessions: missing 'no past sessions' message\n{$output}\n";
            return 1;
        }
        echo "OK testPrintsNoPastSessions\n";
        return 0;
    }

    private function testRendersTableFromLauncherSessions(): int
    {
        $dir = $this->scratch('with-past');
        $service = new AgentSessionService($dir);
        $wa = $dir . '/wa-d01';
        mkdir($wa, 0755, true);
        $service->add($this->makeSession('d01', $wa));

        $past = new \DateTimeImmutable('2026-04-01T12:34:00+00:00');
        $more = new \DateTimeImmutable('2026-04-02T08:00:00+00:00');
        $launcher = new FakeAgentClientLauncher(AgentClient::CLAUDE, [
            new SessionInfo('sess-abc', $past, $past, 12, 'Initial prompt'),
            new SessionInfo('sess-def', $more, $more, 5, 'Resumed prompt'),
        ]);

        $cmd = $this->buildCommand($service, $launcher);

        $output = $this->captureHandle($cmd, ['code' => 'd01']);

        foreach (['Past sessions for d01 (client: claude)', 'sess-abc', 'sess-def', 'Initial prompt', 'Resumed prompt'] as $needle) {
            if (!str_contains($output, $needle)) {
                echo "FAIL testRendersTableFromLauncherSessions: missing '{$needle}' in output\n{$output}\n";
                return 1;
            }
        }
        // The launcher must receive the worktree from sessions.json (developer WA for reviewers too).
        if ($launcher->lastListWorktree !== $wa) {
            echo "FAIL testRendersTableFromLauncherSessions: launcher received '{$launcher->lastListWorktree}' instead of '{$wa}'\n";
            return 1;
        }
        echo "OK testRendersTableFromLauncherSessions\n";
        return 0;
    }

    private function testRefreshesLastSeenWhenSessionPresent(): int
    {
        $dir = $this->scratch('refresh-lastseen');
        $service = new AgentSessionService($dir);
        $wa = $dir . '/wa-d01';
        mkdir($wa, 0755, true);
        $past = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $service->add(new AgentSession(
            code: 'd01',
            client: AgentClient::CLAUDE,
            role: AgentRole::DEVELOPER,
            pid: 0,
            worktree: $wa,
            startedAt: $past,
            lastSeenAt: $past,
            sessionId: null,
        ));

        $cmd = $this->buildCommand($service, new FakeAgentClientLauncher(AgentClient::CLAUDE, []));
        $this->captureHandle($cmd, ['code' => 'd01']);

        $reloaded = $service->get('d01');
        if ($reloaded === null || $reloaded->lastSeenAt <= $past) {
            echo "FAIL testRefreshesLastSeenWhenSessionPresent: last_seen_at not refreshed\n";
            return 1;
        }
        echo "OK testRefreshesLastSeenWhenSessionPresent\n";
        return 0;
    }

    private function buildCommand(AgentSessionService $service, AgentClientLauncher $launcher): AgentSessionsCommand
    {
        $registry = new AgentClientLauncherRegistry();
        $registry->register($launcher);

        return new AgentSessionsCommand(
            Console::getInstance(),
            $service,
            $registry,
        );
    }

    /**
     * @param array<string, bool|string|array<bool|string>> $options
     */
    private function captureHandle(AgentSessionsCommand $command, array $options): string
    {
        ob_start();
        try {
            $command->handle([], $options);
        } finally {
            $output = (string) ob_get_clean();
        }
        return $output;
    }

    private function makeSession(string $code, string $worktree): AgentSession
    {
        $now = new \DateTimeImmutable();
        return new AgentSession(
            code: $code,
            client: AgentClient::CLAUDE,
            role: AgentRole::DEVELOPER,
            pid: 0,
            worktree: $worktree,
            startedAt: $now,
            lastSeenAt: $now,
            sessionId: null,
        );
    }

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
