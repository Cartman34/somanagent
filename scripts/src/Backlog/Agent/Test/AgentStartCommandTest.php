<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Test;

use SoManAgent\Script\Backlog\Agent\Client\AgentClientLauncher;
use SoManAgent\Script\Backlog\Agent\Client\AgentClientLauncherRegistry;
use SoManAgent\Script\Backlog\Agent\Command\AgentStartCommand;
use SoManAgent\Script\Backlog\Agent\Enum\AgentClient;
use SoManAgent\Script\Backlog\Agent\Exception\ClientNotInstalledException;
use SoManAgent\Script\Backlog\Agent\Service\AgentCodeService;
use SoManAgent\Script\Backlog\Agent\Service\AgentContextBuilder;
use SoManAgent\Script\Backlog\Agent\Service\AgentReviewerSelector;
use SoManAgent\Script\Backlog\Agent\Service\AgentSessionService;
use SoManAgent\Script\Backlog\Model\BacklogBoard;
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
 * Command-level tests for {@see AgentStartCommand}.
 *
 * Focuses on the input-validation perimeter and the reviewer-resolution branch
 * that does not require the developer WA preparation: missing/unknown client,
 * role-flag combinations, --reset+--reviewer rejection, and the
 * ClientNotInstalledException raised when the launcher reports unavailable.
 *
 * The reviewer end-to-end launch (would-be-real worktree + git ops) is covered
 * by AgentReviewerSelectorTest at the selector level; here we ensure
 * AgentStartCommand wires its arguments correctly. Heavy collaborators
 * (BacklogWorktreeService, AgentCodeService) are constructed via reflection
 * because the early-failure branches do not exercise them.
 */
final class AgentStartCommandTest
{
    private string $tmpDir;

    /**
     * Creates temp directory for test fixtures.
     */
    public function __construct()
    {
        $this->tmpDir = sys_get_temp_dir() . '/backlog-agent-start-test-' . uniqid('', true);
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

        $failed += $this->testMissingClientArgumentIsRejected();
        $failed += $this->testUnknownClientIsRejected();
        $failed += $this->testRequiresExactlyOneRoleFlag();
        $failed += $this->testRejectsMultipleRoleFlags();
        $failed += $this->testRejectsResetWithReviewer();
        $failed += $this->testRaisesClientNotInstalledWhenLauncherUnavailable();
        $failed += $this->testReviewerModeReusesOwnedReviewingEntry();
        $failed += $this->testReviewerModeRollsBackTakenReviewWhenPreparationFails();
        $failed += $this->testDeveloperResetRefusesDirtyWorktree();
        $failed += $this->testDeveloperResetRemovesAndRecreatesCleanWorktree();

        return $failed;
    }

    private function testMissingClientArgumentIsRejected(): int
    {
        $cmd = $this->buildCommand(new FakeAgentClientLauncher(AgentClient::CLAUDE));

        $threw = false;
        try {
            $cmd->handle([], ['developer' => true]);
        } catch (\RuntimeException $e) {
            $threw = str_contains($e->getMessage(), 'Missing required argument: <client>');
        }

        if (!$threw) {
            echo "FAIL testMissingClientArgumentIsRejected: expected missing-client error\n";
            return 1;
        }
        echo "OK testMissingClientArgumentIsRejected\n";
        return 0;
    }

    private function testUnknownClientIsRejected(): int
    {
        $cmd = $this->buildCommand(new FakeAgentClientLauncher(AgentClient::CLAUDE));

        $threw = false;
        try {
            $cmd->handle(['gibberish'], ['developer' => true]);
        } catch (\RuntimeException $e) {
            $threw = str_contains($e->getMessage(), "Unknown client 'gibberish'");
        }

        if (!$threw) {
            echo "FAIL testUnknownClientIsRejected: expected unknown-client error\n";
            return 1;
        }
        echo "OK testUnknownClientIsRejected\n";
        return 0;
    }

    private function testRequiresExactlyOneRoleFlag(): int
    {
        $cmd = $this->buildCommand(new FakeAgentClientLauncher(AgentClient::CLAUDE));

        $threw = false;
        try {
            $cmd->handle(['claude'], []);
        } catch (\RuntimeException $e) {
            $threw = str_contains($e->getMessage(), 'Exactly one of --developer, --reviewer, or --manager');
        }
        if (!$threw) {
            echo "FAIL testRequiresExactlyOneRoleFlag: expected role-required error\n";
            return 1;
        }
        echo "OK testRequiresExactlyOneRoleFlag\n";
        return 0;
    }

    private function testRejectsMultipleRoleFlags(): int
    {
        $cmd = $this->buildCommand(new FakeAgentClientLauncher(AgentClient::CLAUDE));

        $threw = false;
        try {
            $cmd->handle(['claude'], ['developer' => true, 'reviewer' => true]);
        } catch (\RuntimeException $e) {
            $threw = str_contains($e->getMessage(), 'Only one of --developer, --reviewer, or --manager');
        }
        if (!$threw) {
            echo "FAIL testRejectsMultipleRoleFlags: expected only-one-role error\n";
            return 1;
        }
        echo "OK testRejectsMultipleRoleFlags\n";
        return 0;
    }

    private function testRejectsResetWithReviewer(): int
    {
        $cmd = $this->buildCommand(new FakeAgentClientLauncher(AgentClient::CLAUDE));

        $threw = false;
        try {
            $cmd->handle(['claude'], ['reviewer' => true, 'reset' => true]);
        } catch (\RuntimeException $e) {
            $threw = str_contains($e->getMessage(), '--reset is only allowed with --developer');
        }
        if (!$threw) {
            echo "FAIL testRejectsResetWithReviewer: expected --reset/--reviewer rejection\n";
            return 1;
        }
        echo "OK testRejectsResetWithReviewer\n";
        return 0;
    }

    private function testRaisesClientNotInstalledWhenLauncherUnavailable(): int
    {
        $launcher = new FakeAgentClientLauncher(AgentClient::CLAUDE, available: false);
        $cmd = $this->buildCommand($launcher);

        $threw = false;
        try {
            // --code lets us bypass allocateForRole, which would touch the board.
            $cmd->handle(['claude'], ['developer' => true, 'code' => 'd01']);
        } catch (ClientNotInstalledException) {
            $threw = true;
        } catch (\Throwable $other) {
            echo "FAIL testRaisesClientNotInstalledWhenLauncherUnavailable: expected ClientNotInstalledException, got "
                . get_class($other) . ': ' . $other->getMessage() . "\n";
            return 1;
        }
        if (!$threw) {
            echo "FAIL testRaisesClientNotInstalledWhenLauncherUnavailable: expected ClientNotInstalledException\n";
            return 1;
        }
        echo "OK testRaisesClientNotInstalledWhenLauncherUnavailable\n";
        return 0;
    }

    private function testReviewerModeReusesOwnedReviewingEntry(): int
    {
        // Reviewer with an entry already in `reviewing` must reuse it without re-claiming.
        // The launch is mocked; we only assert the framework calls the launcher with the
        // developer WA stored on the entry and updates sessions.json.
        $dir = $this->scratchDir('reviewer-reuse');
        $boardPath = $dir . '/board.md';
        $worktreesRoot = $dir . '/worktrees';
        $devWa = $worktreesRoot . '/d04';
        mkdir($worktreesRoot, 0755, true);
        mkdir($devWa, 0755, true);

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

        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $sessionService = new AgentSessionService($dir);
        $reviewerSelector = new AgentReviewerSelector($boardService, $sessionService, $worktreesRoot);

        $launcher = new FakeAgentClientLauncher(AgentClient::CLAUDE);
        $registry = new AgentClientLauncherRegistry();
        $registry->register($launcher);

        $codeService = new AgentCodeService($dir, $worktreesRoot, $boardPath, $boardService, $sessionService, new FakeProcessSignaler());
        $contextBuilder = new AgentContextBuilder($dir, $boardPath, $boardService);
        $worktreeService = (new \ReflectionClass(BacklogWorktreeService::class))->newInstanceWithoutConstructor();
        $processRunner = new FakeInteractiveProcessRunner();

        $cmd = new AgentStartCommand(
            $dir,
            $worktreesRoot,
            $boardPath,
            $registry,
            $codeService,
            $sessionService,
            $contextBuilder,
            $worktreeService,
            $reviewerSelector,
            $boardService,
            $processRunner,
            new FakeProcessSignaler(),
            new FakeProcessRunner(),
        );

        // --code=r01 avoids touching AgentCodeService::allocateForRole.
        $previousCwd = getcwd();
        try {
            $cmd->handle(['claude'], ['reviewer' => true, 'code' => 'r01']);
        } finally {
            if ($previousCwd !== false) {
                chdir($previousCwd);
            }
        }

        if ($launcher->lastPreparedWorktree !== $devWa) {
            echo "FAIL testReviewerModeReusesOwnedReviewingEntry: launcher prepared '{$launcher->lastPreparedWorktree}', expected '{$devWa}'\n";
            return 1;
        }
        if ($launcher->lastLaunchedWorktree !== $devWa) {
            echo "FAIL testReviewerModeReusesOwnedReviewingEntry: launcher launched '{$launcher->lastLaunchedWorktree}', expected '{$devWa}'\n";
            return 1;
        }
        if ($processRunner->lastCall === null || $processRunner->lastCall['cwd'] !== $devWa) {
            $cwd = $processRunner->lastCall['cwd'] ?? '<null>';
            echo "FAIL testReviewerModeReusesOwnedReviewingEntry: process runner cwd '{$cwd}', expected '{$devWa}'\n";
            return 1;
        }

        // The board entry must still be `reviewing` (reused, not transitioned again).
        $reloaded = $boardService->loadBoard($boardPath);
        $stillReviewing = false;
        foreach ($reloaded->getEntries(BacklogBoard::SECTION_ACTIVE) as $entry) {
            if ($entry->getFeature() === 'crypto-feature' && $entry->getStage() === 'reviewing') {
                $stillReviewing = true;
                break;
            }
        }
        if (!$stillReviewing) {
            echo "FAIL testReviewerModeReusesOwnedReviewingEntry: reused entry must remain at stage=reviewing\n";
            return 1;
        }

        echo "OK testReviewerModeReusesOwnedReviewingEntry\n";
        return 0;
    }

    private function testReviewerModeRollsBackTakenReviewWhenPreparationFails(): int
    {
        $dir = $this->scratchDir('reviewer-rollback');
        $boardPath = $dir . '/board.md';
        $worktreesRoot = $dir . '/worktrees';
        $devWa = $worktreesRoot . '/d04';
        mkdir($devWa, 0755, true);

        $this->writeBoard($boardPath, [
            '- crypto-feature',
            '  meta:',
            '    kind: feature',
            '    feature: crypto-feature',
            '    branch: feat/crypto-feature',
            '    type: feat',
            '    stage: review',
            '    agent: d04',
        ]);

        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $sessionService = new AgentSessionService($dir);
        $reviewerSelector = new AgentReviewerSelector($boardService, $sessionService, $worktreesRoot);

        $launcher = new FakeAgentClientLauncher(AgentClient::CLAUDE);
        $launcher->prepareException = new \RuntimeException('prepare failed');
        $registry = new AgentClientLauncherRegistry();
        $registry->register($launcher);

        $cmd = new AgentStartCommand(
            $dir,
            $worktreesRoot,
            $boardPath,
            $registry,
            new AgentCodeService($dir, $worktreesRoot, $boardPath, $boardService, $sessionService, new FakeProcessSignaler()),
            $sessionService,
            new AgentContextBuilder($dir, $boardPath, $boardService),
            (new \ReflectionClass(BacklogWorktreeService::class))->newInstanceWithoutConstructor(),
            $reviewerSelector,
            $boardService,
            new FakeInteractiveProcessRunner(),
            new FakeProcessSignaler(),
            new FakeProcessRunner(),
        );

        $previousCwd = getcwd();
        $threw = false;
        try {
            $cmd->handle(['claude'], ['reviewer' => true, 'code' => 'r01']);
        } catch (\RuntimeException $e) {
            $threw = str_contains($e->getMessage(), 'prepare failed');
        } finally {
            if ($previousCwd !== false) {
                chdir($previousCwd);
            }
        }

        if (!$threw) {
            echo "FAIL testReviewerModeRollsBackTakenReviewWhenPreparationFails: expected preparation failure\n";
            return 1;
        }

        $reloaded = $boardService->loadBoard($boardPath);
        $entry = $reloaded->getEntries(BacklogBoard::SECTION_ACTIVE)[0] ?? null;
        if ($entry === null || $entry->getStage() !== BacklogBoard::STAGE_IN_REVIEW || $entry->getReviewer() !== null) {
            echo "FAIL testReviewerModeRollsBackTakenReviewWhenPreparationFails: expected stage=review and reviewer cleared\n";
            return 1;
        }

        echo "OK testReviewerModeRollsBackTakenReviewWhenPreparationFails\n";
        return 0;
    }

    private function testDeveloperResetRefusesDirtyWorktree(): int
    {
        $projectRoot = $this->createGitProject('reset-dirty');
        $worktreesRoot = $projectRoot . '/.agent-worktrees';
        $boardPath = $projectRoot . '/local/backlog-board.md';
        $worktree = $worktreesRoot . '/d05';
        if (!is_dir(dirname($boardPath))) {
            mkdir(dirname($boardPath), 0755, true);
        }
        $this->writeBoard($boardPath, []);
        $this->runShell('git -C ' . escapeshellarg($projectRoot) . ' worktree add --detach ' . escapeshellarg($worktree) . ' HEAD');
        file_put_contents($worktree . '/dirty.txt', 'dirty');

        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $sessionService = new AgentSessionService($projectRoot);
        $launcher = new FakeAgentClientLauncher(AgentClient::CLAUDE);
        $registry = new AgentClientLauncherRegistry();
        $registry->register($launcher);
        $shellRunner = new FakeProcessRunner();
        $shellRunner->outputMap['git status --porcelain|' . $worktree] = '?? dirty.txt';

        $cmd = new AgentStartCommand(
            $projectRoot,
            $worktreesRoot,
            $boardPath,
            $registry,
            new AgentCodeService($projectRoot, $worktreesRoot, $boardPath, $boardService, $sessionService, new FakeProcessSignaler()),
            $sessionService,
            new AgentContextBuilder($projectRoot, $boardPath, $boardService),
            $this->buildRealWorktreeService($projectRoot, $worktreesRoot, $boardService),
            new AgentReviewerSelector($boardService, $sessionService, $worktreesRoot),
            $boardService,
            new FakeInteractiveProcessRunner(),
            new FakeProcessSignaler(),
            $shellRunner,
        );

        $threw = false;
        try {
            $cmd->handle(['claude'], ['developer' => true, 'code' => 'd05', 'reset' => true]);
        } catch (\RuntimeException $e) {
            $threw = str_contains($e->getMessage(), 'is dirty');
        }

        if (!$threw) {
            echo "FAIL testDeveloperResetRefusesDirtyWorktree: expected dirty worktree refusal\n";
            return 1;
        }
        if (!is_dir($worktree)) {
            echo "FAIL testDeveloperResetRefusesDirtyWorktree: dirty worktree must not be removed\n";
            return 1;
        }

        echo "OK testDeveloperResetRefusesDirtyWorktree\n";
        return 0;
    }

    private function testDeveloperResetRemovesAndRecreatesCleanWorktree(): int
    {
        $projectRoot = $this->createGitProject('reset-clean');
        $worktreesRoot = $projectRoot . '/.agent-worktrees';
        $boardPath = $projectRoot . '/local/backlog-board.md';
        $worktree = $worktreesRoot . '/d06';
        $this->writeBoard($boardPath, []);
        $this->runShell('git -C ' . escapeshellarg($projectRoot) . ' worktree add --detach ' . escapeshellarg($worktree) . ' HEAD');

        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $sessionService = new AgentSessionService($projectRoot);
        $launcher = new FakeAgentClientLauncher(AgentClient::CLAUDE);
        $registry = new AgentClientLauncherRegistry();
        $registry->register($launcher);
        $processRunner = new FakeInteractiveProcessRunner();

        $cmd = new AgentStartCommand(
            $projectRoot,
            $worktreesRoot,
            $boardPath,
            $registry,
            new AgentCodeService($projectRoot, $worktreesRoot, $boardPath, $boardService, $sessionService, new FakeProcessSignaler()),
            $sessionService,
            new AgentContextBuilder($projectRoot, $boardPath, $boardService),
            $this->buildRealWorktreeService($projectRoot, $worktreesRoot, $boardService),
            new AgentReviewerSelector($boardService, $sessionService, $worktreesRoot),
            $boardService,
            $processRunner,
            new FakeProcessSignaler(),
            new FakeProcessRunner(),
        );

        $previousCwd = getcwd();
        try {
            chdir($projectRoot);
            $cmd->handle(['claude'], ['developer' => true, 'code' => 'd06', 'reset' => true]);
        } catch (\Throwable $e) {
            echo "FAIL testDeveloperResetRemovesAndRecreatesCleanWorktree: unexpected " . get_class($e) . ': ' . $e->getMessage() . "\n";
            return 1;
        } finally {
            if ($previousCwd !== false) {
                chdir($previousCwd);
            }
        }

        if (!is_dir($worktree) || !file_exists($worktree . '/.git')) {
            echo "FAIL testDeveloperResetRemovesAndRecreatesCleanWorktree: expected recreated git worktree\n";
            return 1;
        }
        if ($processRunner->lastCall === null || $processRunner->lastCall['cwd'] !== $worktree) {
            $cwd = $processRunner->lastCall['cwd'] ?? '<null>';
            echo "FAIL testDeveloperResetRemovesAndRecreatesCleanWorktree: process runner cwd '{$cwd}', expected '{$worktree}'\n";
            return 1;
        }

        echo "OK testDeveloperResetRemovesAndRecreatesCleanWorktree\n";
        return 0;
    }

    /**
     * Builds an AgentStartCommand with reflection-built heavy collaborators.
     *
     * Sufficient for input-validation and "client unavailable" branches, which fail
     * before any worktree / context / session mutation is attempted.
     */
    private function buildCommand(AgentClientLauncher $launcher): AgentStartCommand
    {
        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $sessionService = new AgentSessionService($this->tmpDir);
        $registry = new AgentClientLauncherRegistry();
        $registry->register($launcher);
        $codeService = new AgentCodeService(
            $this->tmpDir,
            $this->tmpDir . '/worktrees',
            $this->tmpDir . '/board.md',
            $boardService,
            $sessionService,
            new FakeProcessSignaler(),
        );
        $contextBuilder = new AgentContextBuilder($this->tmpDir, $this->tmpDir . '/board.md', $boardService);
        $worktreeService = (new \ReflectionClass(BacklogWorktreeService::class))->newInstanceWithoutConstructor();
        $reviewerSelector = new AgentReviewerSelector($boardService, $sessionService, $this->tmpDir . '/worktrees');
        $processRunner = new FakeInteractiveProcessRunner();

        return new AgentStartCommand(
            $this->tmpDir,
            $this->tmpDir . '/worktrees',
            $this->tmpDir . '/board.md',
            $registry,
            $codeService,
            $sessionService,
            $contextBuilder,
            $worktreeService,
            $reviewerSelector,
            $boardService,
            $processRunner,
            new FakeProcessSignaler(),
            new FakeProcessRunner(),
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

    private function scratchDir(string $label): string
    {
        $path = $this->tmpDir . '/' . $label . '-' . uniqid('', true);
        mkdir($path, 0755, true);
        return $path;
    }

    private function createGitProject(string $label): string
    {
        $projectRoot = $this->scratchDir($label);
        mkdir($projectRoot . '/local', 0755, true);
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
