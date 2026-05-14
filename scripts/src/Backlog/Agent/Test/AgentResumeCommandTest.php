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
use SoManAgent\Script\Application;
use SoManAgent\Script\Client\ConsoleClient;
use SoManAgent\Script\Client\FilesystemClient;
use SoManAgent\Script\Client\GitClient;
use SoManAgent\Script\Client\ProjectScriptClient;
use SoManAgent\Script\RetryPolicy;
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

    /**
     * Creates a temporary directory used by each test for an isolated sessions.json.
     */
    public function __construct()
    {
        $this->tmpDir = sys_get_temp_dir() . '/backlog-agent-resume-test-' . uniqid('', true);
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

        $failed += $this->testRefusesWhenNoSessionRecorded();
        $failed += $this->testRefusesWhenClientPidStillAlive();
        $failed += $this->testRefusesWhenWrapperPidStillAlive();
        $failed += $this->testUpdatesLastSeenBeforeAliveCheck();
        $failed += $this->testPersistsReconstructedReviewerWorktreeBeforeLaunchPreparation();

        return $failed;
    }

    private function testRefusesWhenNoSessionRecorded(): int
    {
        $dir = $this->tmpDir . '/nosession-' . uniqid('', true);
        mkdir($dir, 0755, true);

        $cmd = $this->buildCommand(new AgentSessionService($dir), new FakeSessionDriver());

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

        $driver = new FakeSessionDriver();
        $driver->setAlive('d01', true);

        $cmd = $this->buildCommand($service, $driver);

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

        $driver = new FakeSessionDriver();
        $driver->setAlive('d01', true);

        $cmd = $this->buildCommand($service, $driver);

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
        );
        $service->add($session);

        $driver = new FakeSessionDriver();
        $driver->setAlive('d01', true);

        $cmd = $this->buildCommand($service, $driver);

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

    private function testPersistsReconstructedReviewerWorktreeBeforeLaunchPreparation(): int
    {
        $projectRoot = $this->createGitProject('reviewer-reconstruct');
        $worktreesRoot = $projectRoot . '/.agent-worktrees';
        $boardPath = $projectRoot . '/local/backlog-board.md';
        $expectedWorktree = $worktreesRoot . '/d04';
        mkdir(dirname($boardPath), 0755, true);
        $this->writeBoard($boardPath, [
            '- crypto-feature',
            '  meta:',
            '    kind: feature',
            '    feature: crypto-feature',
            '    branch: feat/crypto-feature',
            '    type: feat',
            '    stage: reviewing',
            '    agent: d04',
            '    reviewer: r01',
        ]);
        $this->runShell('git -C ' . escapeshellarg($projectRoot) . ' branch feat/crypto-feature');

        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $sessionService = new AgentSessionService($projectRoot);
        $now = new \DateTimeImmutable();
        $sessionService->add(new AgentSession(
            code: 'r01',
            client: AgentClient::CLAUDE,
            role: AgentRole::REVIEWER,
            pid: 9000,
            worktree: $worktreesRoot . '/missing-d04',
            startedAt: $now,
            lastSeenAt: $now,
            sessionId: 'review-session',
            clientPid: null,
        ));

        $launcher = new FakeAgentClientLauncher(AgentClient::CLAUDE);
        $launcher->prepareException = new \RuntimeException('stop after reconstruction');
        $registry = new AgentClientLauncherRegistry();
        $registry->register($launcher);

        $cmd = $this->buildCommand(
            $sessionService,
            new FakeSessionDriver(),
            $projectRoot,
            $boardPath,
            $registry,
            $boardService,
            $this->buildRealWorktreeService($projectRoot, $worktreesRoot, $boardService),
        );

        $threw = false;
        $previousCwd = getcwd();
        try {
            chdir($projectRoot);
            $cmd->handle([], ['code' => 'r01']);
        } catch (\RuntimeException $e) {
            $threw = str_contains($e->getMessage(), 'stop after reconstruction');
        } finally {
            if ($previousCwd !== false) {
                chdir($previousCwd);
            }
        }

        if (!$threw) {
            echo "FAIL testPersistsReconstructedReviewerWorktreeBeforeLaunchPreparation: expected launcher preparation failure\n";
            return 1;
        }

        $reloaded = $sessionService->get('r01');
        if ($reloaded === null || $reloaded->worktree !== $expectedWorktree) {
            $actual = $reloaded === null ? '<missing>' : $reloaded->worktree;
            echo "FAIL testPersistsReconstructedReviewerWorktreeBeforeLaunchPreparation: session worktree '{$actual}', expected '{$expectedWorktree}'\n";
            return 1;
        }

        echo "OK testPersistsReconstructedReviewerWorktreeBeforeLaunchPreparation\n";
        return 0;
    }

    /**
     * Builds an AgentResumeCommand with the minimum dependencies needed for the early-return branches.
     * Heavy services are constructed without their constructors via reflection.
     */
    private function buildCommand(
        AgentSessionService $sessionService,
        FakeSessionDriver $driver,
        ?string $projectRoot = null,
        ?string $boardPath = null,
        ?AgentClientLauncherRegistry $registry = null,
        ?BacklogBoardService $boardService = null,
        ?BacklogWorktreeService $worktreeService = null,
    ): AgentResumeCommand
    {
        $projectRoot ??= $this->tmpDir;
        $boardPath ??= $this->tmpDir . '/board.md';
        $boardService ??= new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);

        $contextBuilder = new AgentContextBuilder($projectRoot, $boardPath, $boardService);

        $registry ??= new AgentClientLauncherRegistry();

        $worktreeService ??= (new \ReflectionClass(BacklogWorktreeService::class))->newInstanceWithoutConstructor();

        return new AgentResumeCommand(
            $projectRoot,
            $registry,
            $contextBuilder,
            $sessionService,
            $boardService,
            $worktreeService,
            $boardPath,
            $driver,
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
        );
    }

    /**
     * @param list<string> $activeLines
     */
    private function writeBoard(string $path, array $activeLines): void
    {
        $content = "# Test backlog\n\n## To do\n\n## In progress\n\n"
            . implode("\n", $activeLines)
            . "\n\n## Suggestions\n";
        file_put_contents($path, $content);
    }

    private function createGitProject(string $label): string
    {
        $projectRoot = $this->tmpDir . '/' . $label . '-' . uniqid('', true);
        mkdir($projectRoot, 0755, true);
        mkdir($projectRoot . '/scripts/vendor', 0755, true);
        mkdir($projectRoot . '/backend/vendor', 0755, true);
        mkdir($projectRoot . '/frontend/node_modules', 0755, true);
        file_put_contents($projectRoot . '/.env', "DATABASE_URL=sqlite:///%kernel.project_dir%/var/test.db\n");
        file_put_contents($projectRoot . '/scripts/vendor/autoload.php', "<?php\n");
        file_put_contents($projectRoot . '/backend/vendor/autoload.php', "<?php\n");
        file_put_contents($projectRoot . '/frontend/node_modules/.keep', '');
        $this->runShell('git -C ' . escapeshellarg($projectRoot) . ' init --initial-branch=main');
        $this->runShell('git -C ' . escapeshellarg($projectRoot) . ' config user.email test@example.invalid');
        $this->runShell('git -C ' . escapeshellarg($projectRoot) . ' config user.name "Test User"');
        $this->runShell('git -C ' . escapeshellarg($projectRoot) . ' add .');
        $this->runShell('git -C ' . escapeshellarg($projectRoot) . ' commit -m init');

        return $projectRoot;
    }

    private function buildRealWorktreeService(
        string $projectRoot,
        string $worktreesRoot,
        BacklogBoardService $boardService,
    ): BacklogWorktreeService {
        $app = Application::getInstance();
        $console = new ConsoleClient($projectRoot, false, $app, static function (string $message): void {});
        $git = new GitClient(false, $console, new RetryPolicy(0, 0));

        return new BacklogWorktreeService(
            $projectRoot,
            $worktreesRoot,
            false,
            '',
            $boardService,
            $console,
            $git,
            new ProjectScriptClient($console),
            new FilesystemClient(),
        );
    }

    private function runShell(string $command): void
    {
        $output = [];
        $code = 0;
        exec($command . ' 2>&1', $output, $code);
        if ($code !== 0) {
            throw new \RuntimeException(sprintf(
                "Command failed with exit code %d: %s\n%s",
                $code,
                $command,
                implode("\n", $output),
            ));
        }
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
