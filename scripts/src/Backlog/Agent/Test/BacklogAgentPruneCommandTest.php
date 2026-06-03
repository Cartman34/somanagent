<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Agent\Test;

use Sowapps\SoManAgent\Script\Backlog\Agent\Service\AgentSessionService;
use Sowapps\SoManAgent\Script\Backlog\Agent\Command\BacklogAgentPruneCommand;
use Sowapps\SoManAgent\Script\Console;
use Sowapps\SoManAgent\Script\Backlog\Agent\Model\AgentSession;
use Sowapps\SoManAgent\Script\Backlog\Agent\Enum\AgentClient;
use Sowapps\SoManAgent\Script\Backlog\Agent\Enum\AgentRole;
use Sowapps\SoManAgent\Script\Backlog\Agent\Test\FakeSessionDriver;
use Sowapps\SoManAgent\Script\Backlog\Agent\Test\FakeProcessSignaler;
/**
 * Unit tests for BacklogAgentPruneCommand.
 *
 * Covers each pruning branch (never finalised, dead process, worktree gone, healthy)
 * and the --dry-run / --force flags. Uses FakeSessionDriver and a per-test temporary
 * sessions.json so no real processes or worktrees are touched.
 */
final class BacklogAgentPruneCommandTest
{
    private string $tmpDir;

    /**
     * Creates a temporary directory used by each test for an isolated sessions.json.
     */
    public function __construct()
    {
        $this->tmpDir = sys_get_temp_dir() . '/backlog-agent-prune-test-' . uniqid('', true);
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

        $failed += $this->testEmptyRegistryPrintsNoSessions();
        $failed += $this->testRemovesNeverFinalisedEntry();
        $failed += $this->testRemovesDeadProcessKeepsHealthy();
        $failed += $this->testRemovesDeadProcessWithWorktreeGone();
        $failed += $this->testWarnsOnWorktreeGoneWithLiveProcess();
        $failed += $this->testForceRemovesWarningEntries();
        $failed += $this->testDryRunPreservesEntries();
        $failed += $this->testHealthyHealthyEntryIsSilentlyKept();
        $failed += $this->testIdempotentAfterConvergence();
        $failed += $this->testDriverMismatchDirectSessionAliveUnderTmuxDriver();
        $failed += $this->testTmuxOrphanWithEmptyRegistry();
        $failed += $this->testRegistryOrphanAndTmuxOrphan();

        return $failed;
    }

    /**
     * No entries in the registry → friendly message, exit 0, no mutation.
     */
    private function testEmptyRegistryPrintsNoSessions(): int
    {
        $dir = $this->mkSubDir('empty');
        $service = new AgentSessionService($dir);
        $driver = new FakeSessionDriver();

        $cmd = new BacklogAgentPruneCommand(Console::getInstance(), $service, $driver, new FakeProcessSignaler());

        ob_start();
        $exit = $cmd->handle([], []);
        $output = (string) ob_get_clean();

        if ($exit !== 0) {
            return $this->failCleanup($dir, 'testEmptyRegistryPrintsNoSessions', "exit code {$exit}, expected 0");
        }
        if (!str_contains($output, 'No agent sessions')) {
            return $this->failCleanup($dir, 'testEmptyRegistryPrintsNoSessions', 'output missing "No agent sessions"');
        }

        echo "OK testEmptyRegistryPrintsNoSessions\n";
        $this->rmdir($dir);

        return 0;
    }

    /**
     * client_pid null AND tmux_session null → removed regardless of liveness.
     */
    private function testRemovesNeverFinalisedEntry(): int
    {
        $dir = $this->mkSubDir('never-finalised');
        $service = new AgentSessionService($dir);
        $service->add($this->makeSession('d10', clientPid: null, tmuxSession: null, worktree: $dir));

        $driver = new FakeSessionDriver();
        // Even if the driver would have considered the session alive, the rule fires first.
        $driver->setAlive('d10', true);

        $cmd = new BacklogAgentPruneCommand(Console::getInstance(), $service, $driver, new FakeProcessSignaler());

        ob_start();
        $cmd->handle([], []);
        $output = (string) ob_get_clean();

        if ($service->has('d10')) {
            return $this->failCleanup($dir, 'testRemovesNeverFinalisedEntry', 'entry d10 still present after prune');
        }
        if (!str_contains($output, '✓ removed d10')) {
            return $this->failCleanup($dir, 'testRemovesNeverFinalisedEntry', 'output missing "✓ removed d10"');
        }
        if (!str_contains($output, 'never finalised')) {
            return $this->failCleanup($dir, 'testRemovesNeverFinalisedEntry', 'output missing "never finalised" reason');
        }
        if (!str_contains($output, '1 entries removed, 0 warnings')) {
            return $this->failCleanup($dir, 'testRemovesNeverFinalisedEntry', 'summary line missing or incorrect');
        }

        echo "OK testRemovesNeverFinalisedEntry\n";
        $this->rmdir($dir);

        return 0;
    }

    /**
     * isAlive=false with an existing worktree → removed with reason "process dead".
     * A separate healthy session is kept untouched.
     */
    private function testRemovesDeadProcessKeepsHealthy(): int
    {
        $dir = $this->mkSubDir('dead-keep-healthy');
        $service = new AgentSessionService($dir);
        $service->add($this->makeSession('d01', clientPid: 1111, tmuxSession: null, worktree: $dir));
        $service->add($this->makeSession('d02', clientPid: 2222, tmuxSession: null, worktree: $dir));

        $driver = new FakeSessionDriver();
        $driver->setAlive('d01', false);
        $driver->setAlive('d02', true);

        $cmd = new BacklogAgentPruneCommand(Console::getInstance(), $service, $driver, new FakeProcessSignaler());

        ob_start();
        $cmd->handle([], []);
        $output = (string) ob_get_clean();

        if ($service->has('d01')) {
            return $this->failCleanup($dir, 'testRemovesDeadProcessKeepsHealthy', 'd01 still present after prune');
        }
        if (!$service->has('d02')) {
            return $this->failCleanup($dir, 'testRemovesDeadProcessKeepsHealthy', 'd02 was removed but should have been kept (healthy)');
        }
        if (!str_contains($output, '✓ removed d01 (process dead)')) {
            return $this->failCleanup($dir, 'testRemovesDeadProcessKeepsHealthy', 'output missing "✓ removed d01 (process dead)"');
        }
        if (str_contains($output, 'd02')) {
            return $this->failCleanup($dir, 'testRemovesDeadProcessKeepsHealthy', 'output mentions healthy d02 (should be silent)');
        }
        if (!str_contains($output, '1 entries removed, 0 warnings')) {
            return $this->failCleanup($dir, 'testRemovesDeadProcessKeepsHealthy', 'summary line missing or incorrect');
        }

        echo "OK testRemovesDeadProcessKeepsHealthy\n";
        $this->rmdir($dir);

        return 0;
    }

    /**
     * isAlive=false AND worktree missing → removed with reason "process dead, worktree gone".
     */
    private function testRemovesDeadProcessWithWorktreeGone(): int
    {
        $dir = $this->mkSubDir('dead-wt-gone');
        $service = new AgentSessionService($dir);
        $service->add($this->makeSession('d03', clientPid: 3333, tmuxSession: null, worktree: $dir . '/missing-wa'));

        $driver = new FakeSessionDriver();
        $driver->setAlive('d03', false);

        $cmd = new BacklogAgentPruneCommand(Console::getInstance(), $service, $driver, new FakeProcessSignaler());

        ob_start();
        $cmd->handle([], []);
        $output = (string) ob_get_clean();

        if ($service->has('d03')) {
            return $this->failCleanup($dir, 'testRemovesDeadProcessWithWorktreeGone', 'd03 still present after prune');
        }
        if (!str_contains($output, '✓ removed d03 (process dead, worktree gone)')) {
            return $this->failCleanup($dir, 'testRemovesDeadProcessWithWorktreeGone', 'output missing expected "process dead, worktree gone" reason');
        }

        echo "OK testRemovesDeadProcessWithWorktreeGone\n";
        $this->rmdir($dir);

        return 0;
    }

    /**
     * Worktree missing BUT process alive → kept with warning; not removed without --force.
     */
    private function testWarnsOnWorktreeGoneWithLiveProcess(): int
    {
        $dir = $this->mkSubDir('warn-wt-gone-alive');
        $service = new AgentSessionService($dir);
        $service->add($this->makeSession('d04', clientPid: 4444, tmuxSession: null, worktree: $dir . '/missing-wa'));

        $driver = new FakeSessionDriver();
        $driver->setAlive('d04', true);

        $cmd = new BacklogAgentPruneCommand(Console::getInstance(), $service, $driver, new FakeProcessSignaler());

        ob_start();
        $cmd->handle([], []);
        $output = (string) ob_get_clean();

        if (!$service->has('d04')) {
            return $this->failCleanup($dir, 'testWarnsOnWorktreeGoneWithLiveProcess', 'd04 was removed but should have been kept (warning)');
        }
        if (!str_contains($output, '⚠ kept d04 (worktree gone, process still alive)')) {
            return $this->failCleanup($dir, 'testWarnsOnWorktreeGoneWithLiveProcess', 'output missing warning line for d04');
        }
        if (!str_contains($output, 'PID 4444 still alive')) {
            return $this->failCleanup($dir, 'testWarnsOnWorktreeGoneWithLiveProcess', 'output missing live PID 4444 in remediation hint');
        }
        if (!str_contains($output, "php scripts/backlog-agent.php stop --code=d04")) {
            return $this->failCleanup($dir, 'testWarnsOnWorktreeGoneWithLiveProcess', 'output missing stop command hint');
        }
        if (!str_contains($output, '0 entries removed, 1 warnings')) {
            return $this->failCleanup($dir, 'testWarnsOnWorktreeGoneWithLiveProcess', 'summary line missing or incorrect');
        }

        echo "OK testWarnsOnWorktreeGoneWithLiveProcess\n";
        $this->rmdir($dir);

        return 0;
    }

    /**
     * --force removes warning entries; the process itself is not signalled.
     */
    private function testForceRemovesWarningEntries(): int
    {
        $dir = $this->mkSubDir('force-remove-warning');
        $service = new AgentSessionService($dir);
        $service->add($this->makeSession('d05', clientPid: 5555, tmuxSession: null, worktree: $dir . '/missing-wa'));

        $driver = new FakeSessionDriver();
        $driver->setAlive('d05', true);

        $cmd = new BacklogAgentPruneCommand(Console::getInstance(), $service, $driver, new FakeProcessSignaler());

        ob_start();
        $cmd->handle([], ['force' => true]);
        $output = (string) ob_get_clean();

        if ($service->has('d05')) {
            return $this->failCleanup($dir, 'testForceRemovesWarningEntries', 'd05 still present after prune --force');
        }
        if (!str_contains($output, '✓ removed d05')) {
            return $this->failCleanup($dir, 'testForceRemovesWarningEntries', 'output missing "✓ removed d05" line');
        }
        if (!str_contains($output, '--force')) {
            return $this->failCleanup($dir, 'testForceRemovesWarningEntries', 'output does not flag the entry as removed via --force');
        }
        if (!str_contains($output, '1 entries removed, 0 warnings')) {
            return $this->failCleanup($dir, 'testForceRemovesWarningEntries', 'summary line missing or incorrect');
        }

        echo "OK testForceRemovesWarningEntries\n";
        $this->rmdir($dir);

        return 0;
    }

    /**
     * --dry-run prints the plan but writes nothing to the registry.
     */
    private function testDryRunPreservesEntries(): int
    {
        $dir = $this->mkSubDir('dry-run');
        $service = new AgentSessionService($dir);
        $service->add($this->makeSession('d06', clientPid: null, tmuxSession: null, worktree: $dir));
        $service->add($this->makeSession('d07', clientPid: 7777, tmuxSession: null, worktree: $dir));

        $driver = new FakeSessionDriver();
        $driver->setAlive('d07', false);

        $cmd = new BacklogAgentPruneCommand(Console::getInstance(), $service, $driver, new FakeProcessSignaler());

        ob_start();
        $cmd->handle([], ['dry-run' => true]);
        $output = (string) ob_get_clean();

        if (!$service->has('d06') || !$service->has('d07')) {
            return $this->failCleanup($dir, 'testDryRunPreservesEntries', 'entries removed despite --dry-run');
        }
        if (!str_contains($output, '✓ removed d06')) {
            return $this->failCleanup($dir, 'testDryRunPreservesEntries', 'output missing planned removal of d06');
        }
        if (!str_contains($output, '✓ removed d07')) {
            return $this->failCleanup($dir, 'testDryRunPreservesEntries', 'output missing planned removal of d07');
        }
        if (!str_contains($output, '(dry-run)')) {
            return $this->failCleanup($dir, 'testDryRunPreservesEntries', 'summary line missing "(dry-run)" marker');
        }

        echo "OK testDryRunPreservesEntries\n";
        $this->rmdir($dir);

        return 0;
    }

    /**
     * A healthy entry (worktree exists, process alive) is silently kept.
     */
    private function testHealthyHealthyEntryIsSilentlyKept(): int
    {
        $dir = $this->mkSubDir('healthy');
        $service = new AgentSessionService($dir);
        $service->add($this->makeSession('d08', clientPid: 8888, tmuxSession: 'somanagent-d08', worktree: $dir));

        $driver = new FakeSessionDriver();
        $driver->setAlive('d08', true);

        $cmd = new BacklogAgentPruneCommand(Console::getInstance(), $service, $driver, new FakeProcessSignaler());

        ob_start();
        $cmd->handle([], []);
        $output = (string) ob_get_clean();

        if (!$service->has('d08')) {
            return $this->failCleanup($dir, 'testHealthyHealthyEntryIsSilentlyKept', 'healthy d08 was removed');
        }
        if (str_contains($output, 'd08')) {
            return $this->failCleanup($dir, 'testHealthyHealthyEntryIsSilentlyKept', 'healthy d08 should be silently kept (not echoed)');
        }
        if (!str_contains($output, '0 entries removed, 0 warnings')) {
            return $this->failCleanup($dir, 'testHealthyHealthyEntryIsSilentlyKept', 'summary line missing or incorrect');
        }

        echo "OK testHealthyHealthyEntryIsSilentlyKept\n";
        $this->rmdir($dir);

        return 0;
    }

    /**
     * Running prune a second time after convergence is a no-op (only the healthy session remains, no mutation).
     */
    private function testIdempotentAfterConvergence(): int
    {
        $dir = $this->mkSubDir('idempotent');
        $service = new AgentSessionService($dir);
        $service->add($this->makeSession('d09', clientPid: null, tmuxSession: null, worktree: $dir));
        $service->add($this->makeSession('d10', clientPid: 1010, tmuxSession: null, worktree: $dir));

        $driver = new FakeSessionDriver();
        $driver->setAlive('d10', true);

        $cmd = new BacklogAgentPruneCommand(Console::getInstance(), $service, $driver, new FakeProcessSignaler());

        ob_start();
        $cmd->handle([], []);
        ob_end_clean();

        // After first run, d09 removed; d10 stays.
        $afterFirst = $service->load();
        if (isset($afterFirst['d09']) || !isset($afterFirst['d10'])) {
            return $this->failCleanup($dir, 'testIdempotentAfterConvergence', 'first prune did not converge as expected');
        }

        // Second run: must be a no-op.
        ob_start();
        $cmd->handle([], []);
        $output = (string) ob_get_clean();

        if (!str_contains($output, '0 entries removed, 0 warnings')) {
            return $this->failCleanup($dir, 'testIdempotentAfterConvergence', 'second prune is not a no-op');
        }
        $afterSecond = $service->load();
        if (!isset($afterSecond['d10'])) {
            return $this->failCleanup($dir, 'testIdempotentAfterConvergence', 'd10 removed on second prune');
        }

        echo "OK testIdempotentAfterConvergence\n";
        $this->rmdir($dir);

        return 0;
    }

    /**
     * Session created under the direct driver (tmux_session=null, client_pid set) is still alive.
     * prune is run with the default tmux driver, which reports isAlive()=false because tmux_session
     * is null. The conservative signal-0 fallback detects the process is alive and emits a warning
     * instead of removing the entry.
     */
    private function testDriverMismatchDirectSessionAliveUnderTmuxDriver(): int
    {
        $dir = $this->mkSubDir('driver-mismatch');
        $service = new AgentSessionService($dir);
        $service->add($this->makeSession('d11', clientPid: 1234, tmuxSession: null, worktree: $dir));

        // Tmux driver says dead (tmux_session is null — normal for direct sessions).
        $driver = new FakeSessionDriver();
        $driver->setAlive('d11', false);

        // Signal-0 check confirms the client process is still alive.
        $signaler = new FakeProcessSignaler();
        $signaler->setAlive(1234, true);

        $cmd = new BacklogAgentPruneCommand(Console::getInstance(), $service, $driver, $signaler);

        ob_start();
        $cmd->handle([], []);
        $output = (string) ob_get_clean();

        $sessions = $service->load();
        if (!isset($sessions['d11'])) {
            return $this->failCleanup($dir, 'testDriverMismatchDirectSessionAliveUnderTmuxDriver', 'd11 was removed but process was still alive — driver mismatch not caught');
        }
        if (!str_contains($output, '⚠ kept d11 (driver-session mismatch')) {
            return $this->failCleanup($dir, 'testDriverMismatchDirectSessionAliveUnderTmuxDriver', 'output missing driver-mismatch warning for d11');
        }
        if (!str_contains($output, 'PID 1234')) {
            return $this->failCleanup($dir, 'testDriverMismatchDirectSessionAliveUnderTmuxDriver', 'output missing live PID 1234 in hint');
        }
        if (!str_contains($output, 'BACKLOG_AGENT_SESSION_DRIVER=direct')) {
            return $this->failCleanup($dir, 'testDriverMismatchDirectSessionAliveUnderTmuxDriver', 'hint missing BACKLOG_AGENT_SESSION_DRIVER=direct instruction');
        }
        if (!str_contains($output, "stop --code=d11")) {
            return $this->failCleanup($dir, 'testDriverMismatchDirectSessionAliveUnderTmuxDriver', 'hint missing stop --code=d11 instruction');
        }
        if (!str_contains($output, '0 entries removed, 1 warnings')) {
            return $this->failCleanup($dir, 'testDriverMismatchDirectSessionAliveUnderTmuxDriver', 'summary line missing or incorrect');
        }

        echo "OK testDriverMismatchDirectSessionAliveUnderTmuxDriver\n";
        $this->rmdir($dir);

        return 0;
    }

    /**
     * Registry is empty but the driver reports a live tmux session for d11 (orphan driver session).
     * prune must kill d11 via the driver and report removed=1.
     */
    private function testTmuxOrphanWithEmptyRegistry(): int
    {
        $dir = $this->mkSubDir('tmux-orphan-empty-registry');
        $service = new AgentSessionService($dir);

        $driver = new FakeSessionDriver();
        $driver->setLiveSessions(['d11']);

        $cmd = new BacklogAgentPruneCommand(Console::getInstance(), $service, $driver, new FakeProcessSignaler());

        ob_start();
        $exit = $cmd->handle([], []);
        $output = (string) ob_get_clean();

        if ($exit !== 0) {
            return $this->failCleanup($dir, 'testTmuxOrphanWithEmptyRegistry', "exit code {$exit}, expected 0");
        }
        if (!in_array('d11', $driver->killedCodes, true)) {
            return $this->failCleanup($dir, 'testTmuxOrphanWithEmptyRegistry', 'kill(d11) was not called');
        }
        if (!str_contains($output, '✓ removed d11')) {
            return $this->failCleanup($dir, 'testTmuxOrphanWithEmptyRegistry', 'output missing "✓ removed d11"');
        }
        if (!str_contains($output, 'orphan driver session')) {
            return $this->failCleanup($dir, 'testTmuxOrphanWithEmptyRegistry', 'output missing "orphan driver session" reason');
        }
        if (!str_contains($output, '1 entries removed, 0 warnings')) {
            return $this->failCleanup($dir, 'testTmuxOrphanWithEmptyRegistry', 'summary line missing or incorrect');
        }

        echo "OK testTmuxOrphanWithEmptyRegistry\n";
        $this->rmdir($dir);

        return 0;
    }

    /**
     * One dead registry entry for d10 (process dead) + one tmux orphan d11 (no registry entry).
     * prune must remove the registry entry for d10 and kill the orphan d11, total removed=2.
     */
    private function testRegistryOrphanAndTmuxOrphan(): int
    {
        $dir = $this->mkSubDir('registry-orphan-and-tmux-orphan');
        $service = new AgentSessionService($dir);
        $service->add($this->makeSession('d10', clientPid: 1010, tmuxSession: null, worktree: $dir));

        $driver = new FakeSessionDriver();
        $driver->setAlive('d10', false);
        $driver->setLiveSessions(['d11']);

        $cmd = new BacklogAgentPruneCommand(Console::getInstance(), $service, $driver, new FakeProcessSignaler());

        ob_start();
        $exit = $cmd->handle([], []);
        $output = (string) ob_get_clean();

        if ($exit !== 0) {
            return $this->failCleanup($dir, 'testRegistryOrphanAndTmuxOrphan', "exit code {$exit}, expected 0");
        }
        if ($service->has('d10')) {
            return $this->failCleanup($dir, 'testRegistryOrphanAndTmuxOrphan', 'd10 registry entry still present after prune');
        }
        if (!in_array('d11', $driver->killedCodes, true)) {
            return $this->failCleanup($dir, 'testRegistryOrphanAndTmuxOrphan', 'kill(d11) was not called for tmux orphan');
        }
        if (!str_contains($output, '✓ removed d10')) {
            return $this->failCleanup($dir, 'testRegistryOrphanAndTmuxOrphan', 'output missing "✓ removed d10"');
        }
        if (!str_contains($output, '✓ removed d11')) {
            return $this->failCleanup($dir, 'testRegistryOrphanAndTmuxOrphan', 'output missing "✓ removed d11"');
        }
        if (!str_contains($output, '2 entries removed, 0 warnings')) {
            return $this->failCleanup($dir, 'testRegistryOrphanAndTmuxOrphan', 'summary line missing or incorrect');
        }

        echo "OK testRegistryOrphanAndTmuxOrphan\n";
        $this->rmdir($dir);

        return 0;
    }

    private function makeSession(
        string $code,
        ?int $clientPid,
        ?string $tmuxSession,
        string $worktree,
    ): AgentSession {
        $now = new \DateTimeImmutable();

        return new AgentSession(
            code: $code,
            client: AgentClient::CLAUDE,
            role: AgentRole::DEVELOPER,
            pid: 9999,
            worktree: $worktree,
            startedAt: $now,
            lastSeenAt: $now,
            sessionId: null,
            clientPid: $clientPid,
            tmuxSession: $tmuxSession,
        );
    }

    private function mkSubDir(string $name): string
    {
        $dir = $this->tmpDir . '/' . $name . '-' . uniqid('', true);
        mkdir($dir, 0755, true);

        return $dir;
    }

    private function failCleanup(string $dir, string $testName, string $reason): int
    {
        echo "FAIL {$testName}: {$reason}\n";
        $this->rmdir($dir);

        return 1;
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
