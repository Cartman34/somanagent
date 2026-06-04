<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Agent\Test;

use Sowapps\SoManAgent\Script\Backlog\Agent\Enum\AgentClient;
use Sowapps\SoManAgent\Script\Backlog\Enum\BacklogCliOption;
use Sowapps\SoManAgent\Script\Backlog\BacklogPaths;
use Sowapps\SoManAgent\Script\Backlog\Agent\Command\AgentStartCommand;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogConfig;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogBoardService;
use Sowapps\SoManAgent\Script\TextSlugger;
use Sowapps\SoManAgent\Script\Client\FilesystemClient;
use Sowapps\SoManAgent\Script\Backlog\Agent\Service\AgentSessionService;
use Sowapps\SoManAgent\Script\Backlog\Agent\Client\AgentClientLauncherRegistry;
use Sowapps\SoManAgent\Script\Backlog\Agent\Service\AgentCodeService;
use Sowapps\SoManAgent\Script\Backlog\Agent\Service\AgentContextBuilder;
use Sowapps\SoManAgent\Script\Backlog\Agent\Service\AgentReviewerSelector;
use Sowapps\SoManAgent\Script\Backlog\Agent\Service\AgentDeveloperSelector;
use Sowapps\SoManAgent\Script\Backlog\Agent\Service\AgentLaunchPromptResolver;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogWorktreeService;
use Sowapps\SoManAgent\Script\Application;
use Sowapps\SoManAgent\Script\Client\ConsoleClient;
use Sowapps\SoManAgent\Script\Client\GitClient;
use Sowapps\SoManAgent\Script\RetryPolicy;
use Sowapps\SoManAgent\Script\Client\ProjectScriptClient;
use Symfony\Component\Yaml\Yaml;

use Sowapps\SoManAgent\Script\Backlog\Agent\Test\Support\FakeAgentClientLauncher;
use Sowapps\SoManAgent\Script\Backlog\Agent\Test\Support\FakeBacklogCommandRunner;
use Sowapps\SoManAgent\Script\Backlog\Agent\Test\Support\FakeProcessRunner;
use Sowapps\SoManAgent\Script\Backlog\Agent\Test\Support\FakeProcessSignaler;
use Sowapps\SoManAgent\Script\Backlog\Agent\Test\Support\FakeSessionDriver;
use Sowapps\SoManAgent\Script\Backlog\Agent\Test\Support\NullEntryRebaseService;
/**
 * Command-level coverage for start --watch and --loop.
 */
final class AgentWatchModeTest
{
    private string $tmpDir;

    /**
     * Sets up a temporary directory for test fixtures.
     */
    public function __construct()
    {
        $this->tmpDir = sys_get_temp_dir() . '/backlog-agent-watch-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
    }

    /**
     * Removes the temporary directory created by the constructor.
     */
    public function __destruct()
    {
        $this->rmdir($this->tmpDir);
    }

    /**
     * Runs all watch-mode test cases and returns the failure count.
     */
    public function run(): int
    {
        $failed = 0;
        $failed += $this->testDeveloperWatchClaimsTodoAndLaunches();
        $failed += $this->testReviewerWatchClaimsReviewAndLaunches();
        $failed += $this->testWatchWithoutRoleElectsDeveloperForTodoOnly();
        $failed += $this->testWatchWithoutRoleElectsReviewerForReviewOnly();
        $failed += $this->testWatchWithoutRolePrefersReviewerWhenBothExist();
        $failed += $this->testWatchSkipsTodoAlreadyOwnedByLiveSession();
        $failed += $this->testWatchRetriesAfterContention();
        $failed += $this->testLoopRestartsAfterCleanExitAndStopsOnError();
        $failed += $this->testLoopPrintsCompletionTransitionBetweenCycles();
        $failed += $this->testLoopWithoutWatchEnablesWatch();
        $failed += $this->testLoopWithoutWatchUsesWatchConstraints();
        $failed += $this->testAsciiSpinnerWhenLocaleIsNotUtf8();

        return $failed;
    }

    private function testDeveloperWatchClaimsTodoAndLaunches(): int
    {
        $featureSlug = 'todo-feature';
        $fixture = $this->makeFixture('developer-watch');
        $this->writeBoard($fixture['boardPath'], [], [
            ['feature' => $featureSlug, 'type' => 'tech', 'title' => 'Todo feature'],
        ]);
        $runner = new FakeBacklogCommandRunner();
        $runner->onWorkStart = function (string $developerCode, string $entryRef) use ($fixture): void {
            $this->writeBoard($fixture['boardPath'], [
                ['kind' => 'feature', 'stage' => 'development', 'feature' => $entryRef, 'developer' =>$developerCode, 'branch' => 'tech/' . $entryRef, 'type' => 'tech'],
            ]);
        };
        $launcher = new FakeAgentClientLauncher(AgentClient::CLAUDE);
        $driver = new FakeSessionDriver();
        $cmd = $this->buildCommand($fixture, $launcher, $runner, $driver);

        $result = $this->runCommand($fixture['projectRoot'], $cmd, ['claude'], ['developer' => true, 'watch' => true, BacklogCliOption::WATCH_INTERVAL->value => '0', 'code' => 'd10']);
        if ($result !== 0 || $driver->lastLaunchCall === null || $driver->lastLaunchCall['agentCode'] !== 'd10') {
            echo "FAIL testDeveloperWatchClaimsTodoAndLaunches: expected d10 launch, result {$result}\n";
            return 1;
        }
        if (($runner->calls[0]['method'] ?? '') !== 'workStart' || ($runner->calls[0]['entryRef'] ?? '') !== $featureSlug) {
            echo "FAIL testDeveloperWatchClaimsTodoAndLaunches: expected workStart({$featureSlug})\n";
            return 1;
        }

        echo "OK testDeveloperWatchClaimsTodoAndLaunches\n";
        return 0;
    }

    private function testReviewerWatchClaimsReviewAndLaunches(): int
    {
        $featureSlug = 'review-feature';
        $fixture = $this->makeFixture('reviewer-watch');
        $this->writeBoard($fixture['boardPath'], [
            ['kind' => 'feature', 'stage' => 'review', 'feature' => $featureSlug, 'developer' => 'd20', 'branch' => 'tech/' . $featureSlug, 'type' => 'tech'],
        ]);
        $this->addWorktree($fixture['projectRoot'], $fixture['worktreesRoot'] . '/d20');
        $runner = new FakeBacklogCommandRunner();
        $runner->onReviewNext = function (string $reviewerCode, string $entryRef) use ($fixture): void {
            $this->writeBoard($fixture['boardPath'], [
                ['kind' => 'feature', 'stage' => 'reviewing', 'feature' => $entryRef, 'developer' => 'd20', 'reviewer' => $reviewerCode, 'branch' => 'tech/' . $entryRef, 'type' => 'tech'],
            ]);
        };
        $launcher = new FakeAgentClientLauncher(AgentClient::CLAUDE);
        $driver = new FakeSessionDriver();
        $cmd = $this->buildCommand($fixture, $launcher, $runner, $driver);

        $result = $this->runCommand($fixture['projectRoot'], $cmd, ['claude'], ['reviewer' => true, 'watch' => true, BacklogCliOption::WATCH_INTERVAL->value => '0', 'code' => 'r10']);
        if ($result !== 0 || $driver->lastLaunchCall === null || $driver->lastLaunchCall['agentCode'] !== 'r10') {
            echo "FAIL testReviewerWatchClaimsReviewAndLaunches: expected r10 launch, result {$result}\n";
            return 1;
        }
        if (($runner->calls[0]['method'] ?? '') !== 'reviewNext' || ($runner->calls[0]['entryRef'] ?? '') !== $featureSlug) {
            echo "FAIL testReviewerWatchClaimsReviewAndLaunches: expected reviewNext({$featureSlug})\n";
            return 1;
        }

        echo "OK testReviewerWatchClaimsReviewAndLaunches\n";
        return 0;
    }

    private function testWatchWithoutRoleElectsDeveloperForTodoOnly(): int
    {
        return $this->assertRoleElection('watch-elects-dev', [['feature' => 'todo-only', 'type' => 'tech', 'title' => 'Todo']], [], 'd10', 'workStart', 'testWatchWithoutRoleElectsDeveloperForTodoOnly');
    }

    private function testWatchWithoutRoleElectsReviewerForReviewOnly(): int
    {
        return $this->assertRoleElection('watch-elects-reviewer', [], [
            ['kind' => 'feature', 'stage' => 'review', 'feature' => 'review-only', 'developer' => 'd30', 'branch' => 'tech/review-only', 'type' => 'tech'],
        ], 'r10', 'reviewNext', 'testWatchWithoutRoleElectsReviewerForReviewOnly');
    }

    private function testWatchWithoutRolePrefersReviewerWhenBothExist(): int
    {
        return $this->assertRoleElection('watch-prefers-reviewer', [['feature' => 'todo-too', 'type' => 'tech', 'title' => 'Todo']], [
            ['kind' => 'feature', 'stage' => 'review', 'feature' => 'review-wins', 'developer' => 'd31', 'branch' => 'tech/review-wins', 'type' => 'tech'],
        ], 'r10', 'reviewNext', 'testWatchWithoutRolePrefersReviewerWhenBothExist');
    }

    private function testWatchSkipsTodoAlreadyOwnedByLiveSession(): int
    {
        $fixture = $this->makeFixture('watch-skip-live');
        $this->writeBoard($fixture['boardPath'], [
            ['kind' => 'feature', 'stage' => 'development', 'feature' => 'taken', 'developer' => 'd40', 'branch' => 'tech/taken', 'type' => 'tech'],
        ], [
            ['feature' => 'taken', 'type' => 'tech', 'title' => 'Taken'],
            ['feature' => 'free', 'type' => 'tech', 'title' => 'Free'],
        ]);
        $this->writeSession($fixture['projectRoot'], 'd40', 'developer', $fixture['worktreesRoot'] . '/d40');
        $runner = new FakeBacklogCommandRunner();
        $runner->onWorkStart = function (string $developerCode, string $entryRef) use ($fixture): void {
            $this->writeBoard($fixture['boardPath'], [
                ['kind' => 'feature', 'stage' => 'development', 'feature' => $entryRef, 'developer' =>$developerCode, 'branch' => 'tech/' . $entryRef, 'type' => 'tech'],
            ]);
        };
        $driver = new FakeSessionDriver();
        $driver->setAlive('d40', true);
        $cmd = $this->buildCommand($fixture, new FakeAgentClientLauncher(AgentClient::CLAUDE), $runner, $driver);

        $this->runCommand($fixture['projectRoot'], $cmd, ['claude'], ['developer' => true, 'watch' => true, BacklogCliOption::WATCH_INTERVAL->value => '0', 'code' => 'd41']);
        if (($runner->calls[0]['entryRef'] ?? '') !== 'free') {
            echo "FAIL testWatchSkipsTodoAlreadyOwnedByLiveSession: expected free to be claimed\n";
            return 1;
        }

        echo "OK testWatchSkipsTodoAlreadyOwnedByLiveSession\n";
        return 0;
    }

    private function testWatchRetriesAfterContention(): int
    {
        $fixture = $this->makeFixture('watch-retry');
        $this->writeBoard($fixture['boardPath'], [], [
            ['feature' => 'contention', 'type' => 'tech', 'title' => 'Contention'],
        ]);
        $attempts = 0;
        $runner = new FakeBacklogCommandRunner();
        $runner->onWorkStart = function (string $developerCode, string $entryRef) use ($fixture, &$attempts): void {
            $attempts++;
            if ($attempts === 1) {
                throw new \RuntimeException('backlog lock busy');
            }
            $this->writeBoard($fixture['boardPath'], [
                ['kind' => 'feature', 'stage' => 'development', 'feature' => $entryRef, 'developer' =>$developerCode, 'branch' => 'tech/' . $entryRef, 'type' => 'tech'],
            ]);
        };
        $cmd = $this->buildCommand($fixture, new FakeAgentClientLauncher(AgentClient::CLAUDE), $runner, new FakeSessionDriver());
        $this->runCommand($fixture['projectRoot'], $cmd, ['claude'], ['developer' => true, 'watch' => true, BacklogCliOption::WATCH_INTERVAL->value => '0', 'code' => 'd50']);
        if ($attempts !== 2) {
            echo "FAIL testWatchRetriesAfterContention: expected 2 attempts, got {$attempts}\n";
            return 1;
        }

        echo "OK testWatchRetriesAfterContention\n";
        return 0;
    }

    private function testLoopRestartsAfterCleanExitAndStopsOnError(): int
    {
        $fixture = $this->makeFixture('watch-loop');
        $this->writeBoard($fixture['boardPath'], [], [
            ['feature' => 'loop-one', 'type' => 'tech', 'title' => 'Loop one'],
        ]);
        $claims = 0;
        $runner = new FakeBacklogCommandRunner();
        $runner->onWorkStart = function (string $developerCode, string $entryRef) use ($fixture, &$claims): void {
            $claims++;
            $feature = 'loop-' . $claims;
            $this->writeBoard($fixture['boardPath'], [
                ['kind' => 'feature', 'stage' => 'development', 'feature' => $feature, 'developer' =>$developerCode, 'branch' => 'tech/' . $feature, 'type' => 'tech'],
            ]);
        };
        $driver = new FakeSessionDriver();
        $driver->setExitCodeQueue([0, 7]);
        $driver->onLaunchHook = function () use ($fixture, &$claims): void {
            if ($claims === 1) {
                $this->writeBoard($fixture['boardPath'], [], [
                    ['feature' => 'loop-two', 'type' => 'tech', 'title' => 'Loop two'],
                ]);
            }
        };
        $cmd = $this->buildCommand($fixture, new FakeAgentClientLauncher(AgentClient::CLAUDE), $runner, $driver);
        $result = $this->runCommand($fixture['projectRoot'], $cmd, ['claude'], ['developer' => true, 'watch' => true, 'loop' => true, BacklogCliOption::WATCH_INTERVAL->value => '0']);
        if ($result !== 7 || $claims !== 2) {
            echo "FAIL testLoopRestartsAfterCleanExitAndStopsOnError: result={$result}, claims={$claims}\n";
            return 1;
        }

        echo "OK testLoopRestartsAfterCleanExitAndStopsOnError\n";
        return 0;
    }

    private function testLoopPrintsCompletionTransitionBetweenCycles(): int
    {
        $fixture = $this->makeFixture('watch-loop-transition');
        $this->writeBoard($fixture['boardPath'], [], [
            ['feature' => 'cycle-one', 'type' => 'tech', 'title' => 'Cycle one'],
        ]);
        $claims = 0;
        $runner = new FakeBacklogCommandRunner();
        $runner->onWorkStart = function (string $developerCode, string $entryRef) use ($fixture, &$claims): void {
            $claims++;
            $feature = 'cycle-' . $claims;
            $this->writeBoard($fixture['boardPath'], [
                ['kind' => 'feature', 'stage' => 'development', 'feature' => $feature, 'developer' => $developerCode, 'branch' => 'tech/' . $feature, 'type' => 'tech'],
            ]);
        };
        $driver = new FakeSessionDriver();
        $driver->setExitCodeQueue([0, 7]);
        $driver->onLaunchHook = function () use ($fixture, &$claims): void {
            if ($claims === 1) {
                $this->writeBoard($fixture['boardPath'], [], [
                    ['feature' => 'cycle-two', 'type' => 'tech', 'title' => 'Cycle two'],
                ]);
            }
        };
        $cmd = $this->buildCommand($fixture, new FakeAgentClientLauncher(AgentClient::CLAUDE), $runner, $driver);
        $output = $this->captureCommand($fixture['projectRoot'], $cmd, ['claude'], ['developer' => true, 'watch' => true, 'loop' => true, BacklogCliOption::WATCH_INTERVAL->value => '0']);

        if (!str_contains($output, "Task done — waiting for next.\n\n")) {
            echo "FAIL testLoopPrintsCompletionTransitionBetweenCycles: expected transition message, got " . json_encode($output) . "\n";
            return 1;
        }

        echo "OK testLoopPrintsCompletionTransitionBetweenCycles\n";
        return 0;
    }

    private function testLoopWithoutWatchEnablesWatch(): int
    {
        $featureSlug = 'loop-implies-watch';
        $fixture = $this->makeFixture($featureSlug);
        $this->writeBoard($fixture['boardPath'], [], [
            ['feature' => $featureSlug, 'type' => 'tech', 'title' => 'Loop implies watch'],
        ]);
        $runner = new FakeBacklogCommandRunner();
        $runner->onWorkStart = function (string $developerCode, string $entryRef) use ($fixture): void {
            $this->writeBoard($fixture['boardPath'], [
                ['kind' => 'feature', 'stage' => 'development', 'feature' => $entryRef, 'developer' => $developerCode, 'branch' => 'tech/' . $entryRef, 'type' => 'tech'],
            ]);
        };
        $driver = new FakeSessionDriver();
        $driver->setExitCodeQueue([7]);
        $cmd = $this->buildCommand($fixture, new FakeAgentClientLauncher(AgentClient::CLAUDE), $runner, $driver);

        $result = $this->runCommand($fixture['projectRoot'], $cmd, ['claude'], ['developer' => true, 'loop' => true, BacklogCliOption::WATCH_INTERVAL->value => '0', 'code' => 'd70']);
        if ($result !== 7 || $driver->lastLaunchCall === null || $driver->lastLaunchCall['agentCode'] !== 'd70') {
            echo "FAIL testLoopWithoutWatchEnablesWatch: expected d70 launch and non-zero loop stop, result {$result}\n";
            return 1;
        }
        if (($runner->calls[0]['method'] ?? '') !== 'workStart' || ($runner->calls[0]['entryRef'] ?? '') !== $featureSlug) {
            echo "FAIL testLoopWithoutWatchEnablesWatch: expected workStart({$featureSlug})\n";
            return 1;
        }

        echo "OK testLoopWithoutWatchEnablesWatch\n";
        return 0;
    }

    private function testLoopWithoutWatchUsesWatchConstraints(): int
    {
        $fixture = $this->makeFixture('loop-watch-constraints');
        $cmd = $this->buildCommand($fixture, new FakeAgentClientLauncher(AgentClient::CLAUDE), new FakeBacklogCommandRunner(), new FakeSessionDriver());

        $managerRejected = false;
        try {
            $cmd->handle(['claude'], ['manager' => true, 'loop' => true]);
        } catch (\RuntimeException $e) {
            $managerRejected = str_contains($e->getMessage(), '--watch is only supported for developer and reviewer launches.');
        }

        $codeWithoutRoleRejected = false;
        try {
            $cmd->handle(['claude'], ['loop' => true, 'code' => 'd70']);
        } catch (\RuntimeException $e) {
            $codeWithoutRoleRejected = str_contains($e->getMessage(), '--code requires an explicit role when --watch is used.');
        }

        if (!$managerRejected || !$codeWithoutRoleRejected) {
            echo "FAIL testLoopWithoutWatchUsesWatchConstraints: expected watch constraints to apply\n";
            return 1;
        }

        echo "OK testLoopWithoutWatchUsesWatchConstraints\n";
        return 0;
    }

    private function testAsciiSpinnerWhenLocaleIsNotUtf8(): int
    {
        $fixture = $this->makeFixture('ascii-spinner');
        $this->writeBoard($fixture['boardPath'], [], [
            ['feature' => 'ascii', 'type' => 'tech', 'title' => 'Ascii'],
        ]);
        $runner = new FakeBacklogCommandRunner();
        $runner->onWorkStart = function (string $developerCode, string $entryRef) use ($fixture): void {
            $this->writeBoard($fixture['boardPath'], [
                ['kind' => 'feature', 'stage' => 'development', 'feature' => $entryRef, 'developer' =>$developerCode, 'branch' => 'tech/' . $entryRef, 'type' => 'tech'],
            ]);
        };
        $oldLang = $_SERVER['LANG'] ?? null;
        $oldLcAll = $_SERVER['LC_ALL'] ?? null;
        $oldLcCtype = $_SERVER['LC_CTYPE'] ?? null;
        unset($_SERVER['LC_ALL'], $_SERVER['LC_CTYPE']);
        $_SERVER['LANG'] = 'C';
        $cmd = $this->buildCommand($fixture, new FakeAgentClientLauncher(AgentClient::CLAUDE), $runner, new FakeSessionDriver());
        $output = $this->captureCommand($fixture['projectRoot'], $cmd, ['claude'], ['developer' => true, 'watch' => true, BacklogCliOption::WATCH_INTERVAL->value => '0', 'code' => 'd80']);
        if ($oldLang === null) {
            unset($_SERVER['LANG']);
        } else {
            $_SERVER['LANG'] = $oldLang;
        }
        if ($oldLcAll !== null) {
            $_SERVER['LC_ALL'] = $oldLcAll;
        }
        if ($oldLcCtype !== null) {
            $_SERVER['LC_CTYPE'] = $oldLcCtype;
        }
        if (!str_contains($output, '| Watching for work')) {
            echo "FAIL testAsciiSpinnerWhenLocaleIsNotUtf8: expected ASCII spinner output, got " . json_encode($output) . "\n";
            return 1;
        }

        echo "OK testAsciiSpinnerWhenLocaleIsNotUtf8\n";
        return 0;
    }

    /**
     * @param list<array<string, mixed>> $todo
     * @param list<array<string, mixed>> $active
     */
    private function assertRoleElection(string $label, array $todo, array $active, string $expectedCode, string $expectedMethod, string $testName): int
    {
        $fixture = $this->makeFixture($label);
        $this->writeBoard($fixture['boardPath'], $active, $todo);
        if ($expectedMethod === 'reviewNext') {
            $this->addWorktree($fixture['projectRoot'], $fixture['worktreesRoot'] . '/' . ($active[0]['developer'] ?? 'd30'));
        }
        $runner = new FakeBacklogCommandRunner();
        $runner->onWorkStart = function (string $developerCode, string $entryRef) use ($fixture): void {
            $this->writeBoard($fixture['boardPath'], [
                ['kind' => 'feature', 'stage' => 'development', 'feature' => $entryRef, 'developer' =>$developerCode, 'branch' => 'tech/' . $entryRef, 'type' => 'tech'],
            ]);
        };
        $runner->onReviewNext = function (string $reviewerCode, string $entryRef) use ($fixture, $active): void {
            $dev = (string) ($active[0]['developer'] ?? 'd30');
            $this->writeBoard($fixture['boardPath'], [
                ['kind' => 'feature', 'stage' => 'reviewing', 'feature' => $entryRef, 'developer' =>$dev, 'reviewer' => $reviewerCode, 'branch' => 'tech/' . $entryRef, 'type' => 'tech'],
            ]);
        };
        $driver = new FakeSessionDriver();
        $cmd = $this->buildCommand($fixture, new FakeAgentClientLauncher(AgentClient::CLAUDE), $runner, $driver);
        $this->runCommand($fixture['projectRoot'], $cmd, ['claude'], ['watch' => true, BacklogCliOption::WATCH_INTERVAL->value => '0']);

        if ($driver->lastLaunchCall === null || $driver->lastLaunchCall['agentCode'] !== $expectedCode || ($runner->calls[0]['method'] ?? '') !== $expectedMethod) {
            echo "FAIL {$testName}: expected {$expectedMethod} with {$expectedCode}\n";
            return 1;
        }

        echo "OK {$testName}\n";
        return 0;
    }

    /**
     * @return array{projectRoot: string, worktreesRoot: string, boardPath: string}
     */
    private function makeFixture(string $label): array
    {
        $projectRoot = $this->tmpDir . '/' . $label . '-' . uniqid('', true);
        mkdir(BacklogPaths::directory($projectRoot), 0755, true);
        mkdir($projectRoot . '/scripts/vendor', 0755, true);
        mkdir($projectRoot . '/backend/vendor', 0755, true);
        mkdir($projectRoot . '/frontend/node_modules', 0755, true);
        file_put_contents($projectRoot . '/.env', "DATABASE_URL=sqlite:///%kernel.project_dir%/var/test.db\n");
        file_put_contents($projectRoot . '/' . BacklogConfig::LOCAL_PATH, "backlog:\n  max_concurrent_worktrees: 5\n");
        file_put_contents($projectRoot . '/scripts/vendor/autoload.php', "<?php\n");
        file_put_contents($projectRoot . '/backend/vendor/autoload.php', "<?php\n");
        file_put_contents($projectRoot . '/frontend/node_modules/.keep', '');
        $this->runShell('git -C ' . escapeshellarg($projectRoot) . ' init --initial-branch=main');
        $this->runShell('git -C ' . escapeshellarg($projectRoot) . ' config user.email test@example.invalid');
        $this->runShell('git -C ' . escapeshellarg($projectRoot) . ' config user.name "Test User"');
        $this->runShell('git -C ' . escapeshellarg($projectRoot) . ' add .');
        $this->runShell('git -C ' . escapeshellarg($projectRoot) . ' commit -m init');

        return [
            'projectRoot' => $projectRoot,
            'worktreesRoot' => $projectRoot . '/.agent-worktrees',
            'boardPath' => BacklogPaths::boardPath($projectRoot),
        ];
    }

    /**
     * @param array{projectRoot: string, worktreesRoot: string, boardPath: string} $fixture
     */
    private function buildCommand(array $fixture, FakeAgentClientLauncher $launcher, FakeBacklogCommandRunner $runner, FakeSessionDriver $driver): AgentStartCommand
    {
        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $sessionService = new AgentSessionService($fixture['projectRoot']);
        $registry = new AgentClientLauncherRegistry();
        $registry->register($launcher);

        return new AgentStartCommand(
            $fixture['projectRoot'],
            $fixture['worktreesRoot'],
            $fixture['boardPath'],
            $registry,
            new AgentCodeService($fixture['worktreesRoot'], $fixture['boardPath'], $boardService, $sessionService),
            $sessionService,
            new AgentContextBuilder($fixture['projectRoot'], $fixture['boardPath'], $boardService),
            $this->buildWorktreeService($fixture['projectRoot'], $fixture['worktreesRoot'], $boardService),
            new AgentReviewerSelector($boardService, $sessionService, $fixture['worktreesRoot']),
            new AgentDeveloperSelector($boardService),
            $boardService,
            $driver,
            new FakeProcessSignaler(),
            new FakeProcessRunner(),
            $runner,
            new NullEntryRebaseService(),
            null,
            new AgentLaunchPromptResolver(dirname(__DIR__, 4) . '/resources/backlog-agent/launch-prompts.yaml'),
        );
    }

    /**
     * @param list<string> $args
     * @param array<string, string|bool|array<bool|string>> $options
     */
    private function runCommand(string $projectRoot, AgentStartCommand $cmd, array $args, array $options): int
    {
        $previousCwd = getcwd();
        try {
            chdir($projectRoot);
            ob_start();
            $result = $cmd->handle($args, $options);
            ob_end_clean();

            return $result;
        } finally {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            if ($previousCwd !== false) {
                chdir($previousCwd);
            }
        }
    }

    /**
     * @param list<string> $args
     * @param array<string, string|bool|array<bool|string>> $options
     */
    private function captureCommand(string $projectRoot, AgentStartCommand $cmd, array $args, array $options): string
    {
        $previousCwd = getcwd();
        try {
            chdir($projectRoot);
            ob_start();
            $cmd->handle($args, $options);

            return (string) ob_get_clean();
        } finally {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            if ($previousCwd !== false) {
                chdir($previousCwd);
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $activeEntries
     * @param list<array<string, mixed>> $todoEntries
     */
    private function writeBoard(string $path, array $activeEntries, array $todoEntries = []): void
    {
        foreach ($activeEntries as &$entry) {
            $entry['title'] = $entry['title'] ?? ($entry['feature'] ?? 'Entry');
        }
        unset($entry);
        foreach ($todoEntries as &$entry) {
            $entry['title'] = $entry['title'] ?? ($entry['feature'] ?? 'Entry');
        }
        unset($entry);

        $data = [
            'version' => 1,
            'todo' => $todoEntries,
            'active' => $activeEntries,
        ];
        file_put_contents($path, Yaml::dump($data, 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE));
    }

    private function writeSession(string $projectRoot, string $code, string $role, string $worktree): void
    {
        $dir = $projectRoot . '/local/tmp';
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/agent-sessions.json', json_encode([
            $code => [
                'client' => 'claude',
                'role' => $role,
                'pid' => 123,
                'client_pid' => 456,
                'tmux_session' => null,
                'worktree' => $worktree,
                'started_at' => '2026-01-01T00:00:00+00:00',
                'last_seen_at' => '2026-01-01T00:00:00+00:00',
                'session_id' => null,
            ],
        ]));
    }

    private function addWorktree(string $projectRoot, string $worktree): void
    {
        $this->runShell('git -C ' . escapeshellarg($projectRoot) . ' worktree add --detach ' . escapeshellarg($worktree) . ' HEAD');
    }

    private function buildWorktreeService(string $projectRoot, string $worktreesRoot, BacklogBoardService $boardService): BacklogWorktreeService
    {
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
            throw new \RuntimeException(sprintf("Command failed (%d): %s\n%s", $code, $command, implode("\n", $output)));
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
