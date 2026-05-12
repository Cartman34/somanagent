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
        $failed += $this->testUpdateClientProcess();
        $failed += $this->testClientProcessRoundTrip();
        $failed += $this->testLoadIgnoresMalformedEntries();

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

        $service->add($this->makeSession('d01'));
        $service->updateSessionId('d01', 'abc-uuid');

        $got = $service->get('d01');
        if ($got?->sessionId !== 'abc-uuid') {
            echo "FAIL testUpdateSessionId: expected 'abc-uuid', got " . var_export($got?->sessionId, true) . "\n";
            $this->rmdir($dir);
            return 1;
        }
        echo "OK testUpdateSessionId\n";
        $this->rmdir($dir);
        return 0;
    }

    private function testUpdateClientProcess(): int
    {
        $dir = $this->tmpDir . '/clientproc-' . uniqid('', true);
        mkdir($dir, 0755, true);
        $service = new AgentSessionService($dir);

        $service->add($this->makeSession('d01'));
        $service->updateClientProcess('d01', 12346, 12346);

        $got = $service->get('d01');
        if ($got === null || $got->clientPid !== 12346 || $got->processGroupId !== 12346) {
            echo "FAIL testUpdateClientProcess: expected clientPid=12346 pgid=12346, got clientPid="
                . var_export($got?->clientPid, true) . ' pgid=' . var_export($got?->processGroupId, true) . "\n";
            $this->rmdir($dir);
            return 1;
        }
        echo "OK testUpdateClientProcess\n";
        $this->rmdir($dir);
        return 0;
    }

    private function testClientProcessRoundTrip(): int
    {
        $dir = $this->tmpDir . '/roundtrip-' . uniqid('', true);
        mkdir($dir, 0755, true);
        $service = new AgentSessionService($dir);

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
            processGroupId: 300,
        );
        $service->add($session);

        $reloaded = $service->get('d07');
        if ($reloaded === null
            || $reloaded->pid !== 100
            || $reloaded->clientPid !== 200
            || $reloaded->processGroupId !== 300
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
