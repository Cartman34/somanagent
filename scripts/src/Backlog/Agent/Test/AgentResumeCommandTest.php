<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Test;

use SoManAgent\Script\Backlog\Agent\Client\AgentClientLauncherRegistry;
use SoManAgent\Script\Backlog\Agent\Command\AgentResumeCommand;
use SoManAgent\Script\Backlog\Agent\Enum\AgentClient;
use SoManAgent\Script\Backlog\Agent\Enum\AgentRole;
use SoManAgent\Script\Backlog\Agent\Model\AgentSession;
use SoManAgent\Script\Backlog\Agent\Service\AgentContextBuilder;
use SoManAgent\Script\Backlog\Agent\Service\AgentSessionService;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Service\BacklogWorktreeService;
use SoManAgent\Script\Client\FilesystemClient;
use SoManAgent\Script\TextSlugger;

/**
 * Unit tests for AgentResumeCommand — alive-refusal branch and missing-session branch.
 *
 * Heavy dependencies (BacklogWorktreeService) that the failing branches do not exercise are
 * instantiated through ReflectionClass::newInstanceWithoutConstructor so we do not need real
 * worktree / console / git / app wiring.
 */
final class AgentResumeCommandTest
{
    private string $tmpDir;

    public function __construct()
    {
        $this->tmpDir = sys_get_temp_dir() . '/backlog-agent-resume-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
    }

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

        $failed += $this->testRefusesWhenNoSessionRecorded();
        $failed += $this->testRefusesWhenClientPidStillAlive();
        $failed += $this->testRefusesWhenWrapperPidStillAlive();
        $failed += $this->testUpdatesLastSeenBeforeAliveCheck();

        return $failed;
    }

    private function testRefusesWhenNoSessionRecorded(): int
    {
        $dir = $this->tmpDir . '/nosession-' . uniqid('', true);
        mkdir($dir, 0755, true);

        $cmd = $this->buildCommand(new AgentSessionService($dir), new FakeProcessSignaler());

        $threw = false;
        try {
            $cmd->handle([], ['code' => 'd99']);
        } catch (\RuntimeException $e) {
            $threw = str_contains($e->getMessage(), 'No active session found');
        }
        if (!$threw) {
            echo "FAIL testRefusesWhenNoSessionRecorded: expected 'No active session found' error\n";
            $this->rmdir($dir);
            return 1;
        }
        echo "OK testRefusesWhenNoSessionRecorded\n";
        $this->rmdir($dir);
        return 0;
    }

    private function testRefusesWhenClientPidStillAlive(): int
    {
        $dir = $this->tmpDir . '/clientalive-' . uniqid('', true);
        mkdir($dir, 0755, true);

        $service = new AgentSessionService($dir);
        $service->add($this->makeSession('d01', wrapperPid: 100, clientPid: 5000));

        $signaler = new FakeProcessSignaler();
        $signaler->setAlive(5000, true);

        $cmd = $this->buildCommand($service, $signaler);

        $threw = false;
        try {
            $cmd->handle([], ['code' => 'd01']);
        } catch (\RuntimeException $e) {
            $threw = str_contains($e->getMessage(), 'still running');
        }
        if (!$threw) {
            echo "FAIL testRefusesWhenClientPidStillAlive: expected 'still running' error\n";
            $this->rmdir($dir);
            return 1;
        }
        echo "OK testRefusesWhenClientPidStillAlive\n";
        $this->rmdir($dir);
        return 0;
    }

    private function testRefusesWhenWrapperPidStillAlive(): int
    {
        $dir = $this->tmpDir . '/wrapperalive-' . uniqid('', true);
        mkdir($dir, 0755, true);

        $service = new AgentSessionService($dir);
        $service->add($this->makeSession('d01', wrapperPid: 7000, clientPid: null));

        $signaler = new FakeProcessSignaler();
        $signaler->setAlive(7000, true);

        $cmd = $this->buildCommand($service, $signaler);

        $threw = false;
        try {
            $cmd->handle([], ['code' => 'd01']);
        } catch (\RuntimeException $e) {
            $threw = str_contains($e->getMessage(), 'still running');
        }
        if (!$threw) {
            echo "FAIL testRefusesWhenWrapperPidStillAlive: expected 'still running' error\n";
            $this->rmdir($dir);
            return 1;
        }
        echo "OK testRefusesWhenWrapperPidStillAlive\n";
        $this->rmdir($dir);
        return 0;
    }

    private function testUpdatesLastSeenBeforeAliveCheck(): int
    {
        $dir = $this->tmpDir . '/lastseen-resume-' . uniqid('', true);
        mkdir($dir, 0755, true);

        $service = new AgentSessionService($dir);
        $past = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $session = new AgentSession(
            code: 'd01',
            client: AgentClient::CLAUDE,
            role: AgentRole::DEVELOPER,
            pid: 8000,
            worktree: '/tmp',
            startedAt: $past,
            lastSeenAt: $past,
            sessionId: null,
            clientPid: 8000,
            processGroupId: 8000,
        );
        $service->add($session);

        $signaler = new FakeProcessSignaler();
        $signaler->setAlive(8000, true);

        $cmd = $this->buildCommand($service, $signaler);

        try {
            $cmd->handle([], ['code' => 'd01']);
        } catch (\RuntimeException) {
            // expected
        }

        $reloaded = $service->get('d01');
        if ($reloaded === null || $reloaded->lastSeenAt <= $past) {
            echo "FAIL testUpdatesLastSeenBeforeAliveCheck: last_seen_at not refreshed\n";
            $this->rmdir($dir);
            return 1;
        }
        echo "OK testUpdatesLastSeenBeforeAliveCheck\n";
        $this->rmdir($dir);
        return 0;
    }

    /**
     * Builds an AgentResumeCommand with the minimum dependencies needed for the early-return branches.
     * Heavy services are constructed without their constructors via reflection.
     */
    private function buildCommand(AgentSessionService $sessionService, FakeProcessSignaler $signaler): AgentResumeCommand
    {
        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);

        $contextBuilder = new AgentContextBuilder($this->tmpDir, $this->tmpDir . '/board.md', $boardService);

        $registry = new AgentClientLauncherRegistry();

        $worktreeService = (new \ReflectionClass(BacklogWorktreeService::class))->newInstanceWithoutConstructor();
        $processRunner = new FakeInteractiveProcessRunner();

        return new AgentResumeCommand(
            $this->tmpDir,
            $registry,
            $contextBuilder,
            $sessionService,
            $boardService,
            $worktreeService,
            $this->tmpDir . '/board.md',
            $processRunner,
            $signaler,
        );
    }

    /**
     * @param int|null $clientPid
     */
    private function makeSession(string $code, int $wrapperPid, ?int $clientPid): AgentSession
    {
        $now = new \DateTimeImmutable();
        return new AgentSession(
            code: $code,
            client: AgentClient::CLAUDE,
            role: AgentRole::DEVELOPER,
            pid: $wrapperPid,
            worktree: '/tmp/fake',
            startedAt: $now,
            lastSeenAt: $now,
            sessionId: null,
            clientPid: $clientPid,
            processGroupId: null,
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
