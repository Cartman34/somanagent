<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Test;

use SoManAgent\Script\Backlog\Agent\Enum\AgentClient;
use SoManAgent\Script\Backlog\Agent\Enum\AgentRole;
use SoManAgent\Script\Backlog\Agent\Model\AgentSession;
use SoManAgent\Script\Backlog\Agent\Service\AgentSessionService;

/**
 * Unit tests for AgentSessionService.
 */
final class AgentSessionServiceTest
{
    private string $tmpDir;

    /**
     * Creates a temporary directory for test fixtures.
     */
    public function __construct()
    {
        $this->tmpDir = sys_get_temp_dir() . '/backlog-agent-sessions-test-' . uniqid('', true);
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

        $failed += $this->testLoadEmptyWhenFileAbsent();
        $failed += $this->testAddAndGet();
        $failed += $this->testRemove();
        $failed += $this->testUpdateLastSeen();
        $failed += $this->testUpdateSessionId();
        $failed += $this->testUpdateClientPid();
        $failed += $this->testUpdateTmuxSession();
        $failed += $this->testClientProcessRoundTrip();
        $failed += $this->testLoadsLegacyEntryWithoutClientProcessFields();
        $failed += $this->testIsAliveUsesClientPidFirst();
        $failed += $this->testLoadIgnoresMalformedEntries();
        $failed += $this->testLogLaunchAppendsLine();

        return $failed;
    }

    private function testLoadEmptyWhenFileAbsent(): int
    {
        $service = new AgentSessionService($this->tmpDir . '/empty-' . uniqid('', true));
        $sessions = $service->load();
        if ($sessions !== []) {
            echo "FAIL testLoadEmptyWhenFileAbsent: expected empty array\n";
            return 1;
        }
        echo "OK testLoadEmptyWhenFileAbsent\n";
        return 0;
    }

    private function testAddAndGet(): int
    {
        $dir = $this->tmpDir . '/add-' . uniqid('', true);
        mkdir($dir, 0755, true);
        $service = new AgentSessionService($dir);

        $session = $this->makeSession('d01');
        $service->add($session);

        $got = $service->get('d01');
        if ($got === null || $got->code !== 'd01' || $got->client !== AgentClient::CLAUDE) {
            echo "FAIL testAddAndGet: unexpected result\n";
            $this->rmdir($dir);
            return 1;
        }
        echo "OK testAddAndGet\n";
        $this->rmdir($dir);
        return 0;
    }

    private function testRemove(): int
    {
        $dir = $this->tmpDir . '/remove-' . uniqid('', true);
        mkdir($dir, 0755, true);
        $service = new AgentSessionService($dir);

        $service->add($this->makeSession('d01'));
        $service->remove('d01');

        if ($service->has('d01')) {
            echo "FAIL testRemove: session still present after remove\n";
            $this->rmdir($dir);
            return 1;
        }
        echo "OK testRemove\n";
        $this->rmdir($dir);
        return 0;
    }

    private function testUpdateLastSeen(): int
    {
        $dir = $this->tmpDir . '/lastseen-' . uniqid('', true);
        mkdir($dir, 0755, true);
        $service = new AgentSessionService($dir);

        $before = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $session = new AgentSession('d01', AgentClient::CLAUDE, AgentRole::DEVELOPER, 42, '/fake', $before, $before, null);
        $service->add($session);

        sleep(0); // ensure different timestamp is theoretically possible
        $service->updateLastSeen('d01');
        $got = $service->get('d01');

        if ($got === null) {
            echo "FAIL testUpdateLastSeen: session not found\n";
            $this->rmdir($dir);
            return 1;
        }
        echo "OK testUpdateLastSeen\n";
        $this->rmdir($dir);
        return 0;
    }

    private function testUpdateSessionId(): int
    {
        $dir = $this->tmpDir . '/sessionid-' . uniqid('', true);
        mkdir($dir, 0755, true);
        $service = new AgentSessionService($dir);

        $sessionId = 'abc-uuid';
        $service->add($this->makeSession('d01'));
        $service->updateSessionId('d01', $sessionId);

        $got = $service->get('d01');
        if ($got?->sessionId !== $sessionId) {
            echo "FAIL testUpdateSessionId: expected '$sessionId', got " . var_export($got?->sessionId, true) . "\n";
            $this->rmdir($dir);
            return 1;
        }
        echo "OK testUpdateSessionId\n";
        $this->rmdir($dir);
        return 0;
    }

    private function testUpdateClientPid(): int
    {
        $dir = $this->tmpDir . '/clientpid-' . uniqid('', true);
        mkdir($dir, 0755, true);
        $service = new AgentSessionService($dir);

        $service->add($this->makeSession('d01'));
        $service->updateClientPid('d01', 12346);

        $got = $service->get('d01');
        if ($got === null || $got->clientPid !== 12346) {
            echo "FAIL testUpdateClientPid: expected clientPid=12346, got " . var_export($got?->clientPid, true) . "\n";
            $this->rmdir($dir);
            return 1;
        }
        echo "OK testUpdateClientPid\n";
        $this->rmdir($dir);
        return 0;
    }

    private function testUpdateTmuxSession(): int
    {
        $dir = $this->tmpDir . '/tmuxsession-' . uniqid('', true);
        mkdir($dir, 0755, true);
        $service = new AgentSessionService($dir);

        $tmuxSession = 'somanagent-d01';
        $service->add($this->makeSession('d01'));
        $service->updateTmuxSession('d01', $tmuxSession);

        $got = $service->get('d01');
        if ($got === null || $got->tmuxSession !== $tmuxSession) {
            echo "FAIL testUpdateTmuxSession: expected tmuxSession='$tmuxSession', got " . var_export($got?->tmuxSession, true) . "\n";
            $this->rmdir($dir);
            return 1;
        }
        echo "OK testUpdateTmuxSession\n";
        $this->rmdir($dir);
        return 0;
    }

    private function testClientProcessRoundTrip(): int
    {
        $dir = $this->tmpDir . '/roundtrip-' . uniqid('', true);
        mkdir($dir, 0755, true);
        $service = new AgentSessionService($dir);

        $tmuxSession = 'somanagent-d07';
        $now = new \DateTimeImmutable('2026-05-12T10:00:00+00:00');
        $session = new AgentSession(
            code: 'd07',
            client: AgentClient::CLAUDE,
            role: AgentRole::DEVELOPER,
            pid: 100,
            worktree: '/tmp/wa',
            startedAt: $now,
            lastSeenAt: $now,
            sessionId: 'abc',
            clientPid: 200,
            tmuxSession: $tmuxSession,
        );
        $service->add($session);

        $reloaded = $service->get('d07');
        if ($reloaded === null
            || $reloaded->pid !== 100
            || $reloaded->clientPid !== 200
            || $reloaded->tmuxSession !== $tmuxSession
            || $reloaded->sessionId !== 'abc'
        ) {
            echo "FAIL testClientProcessRoundTrip: unexpected reloaded session\n";
            $this->rmdir($dir);
            return 1;
        }
        echo "OK testClientProcessRoundTrip\n";
        $this->rmdir($dir);
        return 0;
    }

    private function testLoadsLegacyEntryWithoutClientProcessFields(): int
    {
        $dir = $this->tmpDir . '/legacy-' . uniqid('', true);
        mkdir($dir . '/local/tmp', 0755, true);
        file_put_contents($dir . '/local/tmp/agent-sessions.json', json_encode([
            'd01' => [
                'client' => 'claude',
                'role' => 'developer',
                'pid' => 4242,
                'worktree' => '/tmp/wa',
                'started_at' => '2026-01-01T00:00:00+00:00',
                'last_seen_at' => '2026-01-01T00:00:00+00:00',
                'session_id' => null,
            ],
        ]));

        $service = new AgentSessionService($dir);
        $session = $service->get('d01');

        if ($session === null || $session->clientPid !== null || $session->tmuxSession !== null || $session->pid !== 4242) {
            echo "FAIL testLoadsLegacyEntryWithoutClientProcessFields: legacy schema not handled gracefully\n";
            $this->rmdir($dir);
            return 1;
        }
        echo "OK testLoadsLegacyEntryWithoutClientProcessFields\n";
        $this->rmdir($dir);
        return 0;
    }

    private function testIsAliveUsesClientPidFirst(): int
    {
        $now = new \DateTimeImmutable();

        $signaler = new FakeProcessSignaler();
        $signaler->setAlive(9001, true);  // clientPid alive
        $signaler->setAlive(1, false);    // wrapper pid dead (ensures clientPid is checked first)

        // Live client_pid wins even when wrapper pid is reported dead.
        $live = new AgentSession(
            code: 'd01',
            client: AgentClient::CLAUDE,
            role: AgentRole::DEVELOPER,
            pid: 1,
            worktree: '/tmp',
            startedAt: $now,
            lastSeenAt: $now,
            sessionId: null,
            clientPid: 9001,
        );
        if (!$live->isAlive($signaler)) {
            echo "FAIL testIsAliveUsesClientPidFirst: expected alive when clientPid is alive\n";
            return 1;
        }

        // Both PIDs dead: isAlive must return false.
        $deadSignaler = new FakeProcessSignaler();
        $dead = new AgentSession(
            code: 'd02',
            client: AgentClient::CLAUDE,
            role: AgentRole::DEVELOPER,
            pid: 9002,
            worktree: '/tmp',
            startedAt: $now,
            lastSeenAt: $now,
            sessionId: null,
            clientPid: 9003,
        );
        if ($dead->isAlive($deadSignaler)) {
            echo "FAIL testIsAliveUsesClientPidFirst: expected dead when both PIDs are dead\n";
            return 1;
        }

        echo "OK testIsAliveUsesClientPidFirst\n";
        return 0;
    }

    private function testLoadIgnoresMalformedEntries(): int
    {
        $dir = $this->tmpDir . '/malformed-' . uniqid('', true);
        mkdir($dir . '/local/tmp', 0755, true);
        file_put_contents($dir . '/local/tmp/agent-sessions.json', json_encode([
            'd01' => ['client' => 'invalid-client', 'role' => 'developer', 'pid' => 1, 'worktree' => '/x', 'started_at' => 'now', 'last_seen_at' => 'now', 'session_id' => null],
            'd02' => ['client' => 'claude', 'role' => 'developer', 'pid' => 2, 'worktree' => '/y', 'started_at' => '2026-01-01T00:00:00+00:00', 'last_seen_at' => '2026-01-01T00:00:00+00:00', 'session_id' => null],
        ]));

        $service = new AgentSessionService($dir);
        $sessions = $service->load();

        if (count($sessions) !== 1 || !isset($sessions['d02'])) {
            echo "FAIL testLoadIgnoresMalformedEntries: unexpected result, count=" . count($sessions) . "\n";
            $this->rmdir($dir);
            return 1;
        }
        echo "OK testLoadIgnoresMalformedEntries\n";
        $this->rmdir($dir);
        return 0;
    }

    private function testLogLaunchAppendsLine(): int
    {
        $dir = $this->tmpDir . '/launches-' . uniqid('', true);
        mkdir($dir . '/local/tmp', 0755, true);
        $service = new AgentSessionService($dir);

        $service->logLaunch('d01', AgentRole::DEVELOPER, AgentClient::CLAUDE, 'tmux', '/usr/bin/claude', ['--add-dir', '/some/path with spaces'], 12345);
        $service->logLaunch('d01', AgentRole::DEVELOPER, AgentClient::CLAUDE, 'tmux', '/usr/bin/claude', ['--resume'], 12399);

        $logPath = $dir . '/local/tmp/agent-launches.log';
        if (!is_file($logPath)) {
            echo "FAIL testLogLaunchAppendsLine: log file not created\n";
            $this->rmdir($dir);
            return 1;
        }

        $content = file_get_contents($logPath);
        if ($content === false) {
            echo "FAIL testLogLaunchAppendsLine: could not read log file\n";
            $this->rmdir($dir);
            return 1;
        }
        $lines = array_filter(explode("\n", $content));
        if (count($lines) !== 2) {
            echo "FAIL testLogLaunchAppendsLine: expected 2 lines, got " . count($lines) . "\n";
            $this->rmdir($dir);
            return 1;
        }

        $fields = explode("\t", array_values($lines)[0]);
        if (count($fields) !== 7) {
            echo "FAIL testLogLaunchAppendsLine: expected 7 tab-separated fields, got " . count($fields) . "\n";
            $this->rmdir($dir);
            return 1;
        }

        [, $code, $role, $client, $driver, $cmdLine, $pid] = $fields;
        if ($code !== 'd01' || $role !== 'developer' || $client !== 'claude' || $driver !== 'tmux' || (int) $pid !== 12345) {
            echo "FAIL testLogLaunchAppendsLine: unexpected field values in first line\n";
            $this->rmdir($dir);
            return 1;
        }

        if (!str_contains($cmdLine, '/usr/bin/claude') || !str_contains($cmdLine, '--add-dir')) {
            echo "FAIL testLogLaunchAppendsLine: command line missing expected tokens\n";
            $this->rmdir($dir);
            return 1;
        }

        echo "OK testLogLaunchAppendsLine\n";
        $this->rmdir($dir);
        return 0;
    }

    private function makeSession(string $code): AgentSession
    {
        $now = new \DateTimeImmutable();
        return new AgentSession($code, AgentClient::CLAUDE, AgentRole::DEVELOPER, 42, '/fake/worktree', $now, $now, null);
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
