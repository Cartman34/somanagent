<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Agent\Test;

use Sowapps\SoManAgent\Script\Backlog\Agent\Enum\AgentClient;
use Sowapps\SoManAgent\Script\Backlog\Enum\BacklogCliOption;
use Sowapps\SoManAgent\Script\Backlog\Agent\Exception\ClientNotInstalledException;
use Sowapps\SoManAgent\Script\Backlog\BacklogPaths;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogBoardService;
use Sowapps\SoManAgent\Script\TextSlugger;
use Sowapps\SoManAgent\Script\Client\FilesystemClient;
use Sowapps\SoManAgent\Script\Backlog\Agent\Service\AgentSessionService;
use Sowapps\SoManAgent\Script\Backlog\Agent\Service\AgentReviewerSelector;
use Sowapps\SoManAgent\Script\Backlog\Agent\Client\AgentClientLauncherRegistry;
use Sowapps\SoManAgent\Script\Backlog\Agent\Service\AgentCodeService;
use Sowapps\SoManAgent\Script\Backlog\Agent\Service\AgentContextBuilder;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogConfig;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogWorktreeService;
use Sowapps\SoManAgent\Script\Backlog\Agent\Command\AgentStartCommand;
use Sowapps\SoManAgent\Script\Backlog\Agent\Service\AgentDeveloperSelector;
use Sowapps\SoManAgent\Script\Backlog\Agent\Enum\AgentRole;
use Sowapps\SoManAgent\Script\Backlog\Model\BacklogBoard;
use Sowapps\SoManAgent\Script\Backlog\Agent\Exception\ActiveSessionException;
use Sowapps\SoManAgent\Script\Backlog\Service\EntryRebaseResult;
use Sowapps\SoManAgent\Script\Backlog\Agent\Client\AgentClientLauncher;
use Sowapps\SoManAgent\Script\Backlog\Agent\Service\AgentModelResolver;
use Sowapps\SoManAgent\Script\Backlog\Agent\Service\AgentLaunchPromptResolver;
use Sowapps\SoManAgent\Script\Application;
use Sowapps\SoManAgent\Script\Client\ConsoleClient;
use Sowapps\SoManAgent\Script\Client\GitClient;
use Sowapps\SoManAgent\Script\RetryPolicy;
use Sowapps\SoManAgent\Script\Client\ProjectScriptClient;
use Sowapps\SoManAgent\Script\Backlog\Agent\Model\AgentSession;
use Sowapps\SoManAgent\Script\Backlog\Model\BoardEntry;
use Sowapps\SoManAgent\Script\Backlog\Agent\Enum\WaOccupantChoice;
use Symfony\Component\Yaml\Yaml;

use Sowapps\SoManAgent\Script\Backlog\Agent\Test\Support\FakeAgentClientLauncher;
use Sowapps\SoManAgent\Script\Backlog\Agent\Test\Support\FakeBacklogCommandRunner;
use Sowapps\SoManAgent\Script\Backlog\Agent\Test\Support\FakeEntryRebaseService;
use Sowapps\SoManAgent\Script\Backlog\Agent\Test\Support\FakeProcessRunner;
use Sowapps\SoManAgent\Script\Backlog\Agent\Test\Support\FakeProcessSignaler;
use Sowapps\SoManAgent\Script\Backlog\Agent\Test\Support\FakeSessionDriver;
use Sowapps\SoManAgent\Script\Backlog\Agent\Test\Support\NullEntryRebaseService;
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
    private const FEATURE_CRYPTO = 'crypto-feature';
    private const FEATURE_MY = 'my-feature';
    private const FEATURE_ROLLBACK = 'rollback-feature';

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
        $failed += $this->testRejectsForceNewWithReviewer();
        $failed += $this->testRaisesClientNotInstalledWhenLauncherUnavailable();
        $failed += $this->testTierOverrideIsForwardedToLauncher();
        $failed += $this->testClaudeEffortOverrideIsForwardedToLauncher();
        $failed += $this->testGeminiEffortOverridePrintsWarningWithoutEffortArg();
        $failed += $this->testReviewerModeReusesOwnedReviewingEntry();
        $failed += $this->testReviewerModeCallsReviewNextWhenTakingEntry();
        $failed += $this->testManagerStartDoesNotSendInitialPrompt();
        $failed += $this->testReviewerModeRollsBackViaCancelWhenPreparationFails();
        $failed += $this->testReviewerStartsSuccessfullyWhenDeveloperIsActive();
        $failed += $this->testReviewerRefusedWhenAnotherReviewerIsActive();
        $failed += $this->testDeveloperResetRefusesDirtyWorktree();
        $failed += $this->testDeveloperResetRemovesAndRecreatesCleanWorktree();
        $failed += $this->testLaunchKeepsSessionEntryWhenDriverReportsDetach();
        $failed += $this->testDeveloperAutoPicksFirstQueuedTask();
        $failed += $this->testDeveloperRefusesWhenTodoEmpty();
        $failed += $this->testDeveloperSkipsAutoPickWhenAlreadyHasActiveEntry();
        $failed += $this->testDeveloperRollsBackViaEntryReleaseWhenPreparationFails();
        $failed += $this->testHandleRestoresCwdOnSuccess();
        $failed += $this->testHandleRestoresCwdWhenWorktreeDeletedDuringLaunch();
        $failed += $this->testDeveloperStageReviewRefuses();
        $failed += $this->testDeveloperStageReviewingRefuses();
        $failed += $this->testDeveloperStageApprovedUpToDateSkipsAgent();
        $failed += $this->testDeveloperStageApprovedConflictLaunchesAgentWithConflictPrompt();
        $failed += $this->testReviewerStageApprovedRefusesViaResolver();
        $failed += $this->testStartAttachesWhenSessionIsLive();
        $failed += $this->testStartCleansGhostSessionWhenDriverDead();
        $failed += $this->testStartCleansGhostSessionWhenWorktreeAbsent();
        $failed += $this->testForceNewDropsLiveSessionAndCreatesNew();
        $failed += $this->testWaOccupantAcceptAdoptsExistingSession();
        $failed += $this->testWaOccupantPassSkipsToNextEntry();
        $failed += $this->testWaOccupantQuitAbortsPickerReturnsZero();
        $failed += $this->testDeadRegistrySessionIsCleanedAndEntryTakenNormally();
        $failed += $this->testAliveAttachedSessionIsAutoPassedToNextEntry();
        $failed += $this->testPassOptionNotOfferedForSingleCandidate();

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

    private function testRejectsForceNewWithReviewer(): int
    {
        $cmd = $this->buildCommand(new FakeAgentClientLauncher(AgentClient::CLAUDE));

        $threw = false;
        try {
            $cmd->handle(['claude'], ['reviewer' => true, BacklogCliOption::FORCE_NEW->value => true]);
        } catch (\RuntimeException $e) {
            $threw = str_contains($e->getMessage(), '--force-new is only allowed with --developer');
        }
        if (!$threw) {
            echo "FAIL testRejectsForceNewWithReviewer: expected --force-new/--reviewer rejection\n";
            return 1;
        }
        echo "OK testRejectsForceNewWithReviewer\n";
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

    private function testTierOverrideIsForwardedToLauncher(): int
    {
        $projectRoot = $this->createGitProject('model-tier');
        $this->writeBoard(BacklogPaths::boardPath($projectRoot), [
            [
                'kind' => 'feature',
                'stage' => 'development',
                'feature' => 'tier-feature',
                'developer' => 'd11',
                'branch' => 'feat/tier-feature',
            ],
        ]);
        $launcher = new FakeAgentClientLauncher(AgentClient::CODEX);
        $cmd = $this->buildProjectCommand($projectRoot, $launcher, $this->buildModelResolver());

        $previousCwd = getcwd();
        try {
            chdir($projectRoot);
            $cmd->handle(['codex'], ['developer' => true, 'code' => 'd11', 'tier' => 'economy']);
        } finally {
            if ($previousCwd !== false) {
                chdir($previousCwd);
            }
        }

        $expected = ['--model', 'gpt-5.4-mini', '--config', 'model_reasoning_effort="medium"'];
        if ($launcher->lastResolvedModelCliArgs !== $expected) {
            echo "FAIL testTierOverrideIsForwardedToLauncher: expected " . json_encode($expected)
                . ', got ' . json_encode($launcher->lastResolvedModelCliArgs) . "\n";
            return 1;
        }

        echo "OK testTierOverrideIsForwardedToLauncher\n";
        return 0;
    }

    private function testClaudeEffortOverrideIsForwardedToLauncher(): int
    {
        $projectRoot = $this->createGitProject('model-effort');
        $this->writeBoard(BacklogPaths::boardPath($projectRoot), [
            [
                'kind' => 'feature',
                'stage' => 'development',
                'feature' => 'effort-feature',
                'developer' => 'd12',
                'branch' => 'feat/effort-feature',
            ],
        ]);
        $launcher = new FakeAgentClientLauncher(AgentClient::CLAUDE);
        $cmd = $this->buildProjectCommand($projectRoot, $launcher, $this->buildModelResolver());

        $previousCwd = getcwd();
        try {
            chdir($projectRoot);
            $cmd->handle(['claude'], ['developer' => true, 'code' => 'd12', 'effort' => 'high']);
        } finally {
            if ($previousCwd !== false) {
                chdir($previousCwd);
            }
        }

        $expected = ['--model', 'sonnet', '--effort', 'high'];
        if ($launcher->lastResolvedModelCliArgs !== $expected) {
            echo "FAIL testClaudeEffortOverrideIsForwardedToLauncher: expected " . json_encode($expected)
                . ', got ' . json_encode($launcher->lastResolvedModelCliArgs) . "\n";
            return 1;
        }

        echo "OK testClaudeEffortOverrideIsForwardedToLauncher\n";
        return 0;
    }

    private function testGeminiEffortOverridePrintsWarningWithoutEffortArg(): int
    {
        $projectRoot = $this->createGitProject('model-gemini-warning');
        $this->writeBoard(BacklogPaths::boardPath($projectRoot), [
            [
                'kind' => 'feature',
                'stage' => 'development',
                'feature' => 'gemini-feature',
                'developer' => 'd13',
                'branch' => 'feat/gemini-feature',
            ],
        ]);
        $launcher = new FakeAgentClientLauncher(AgentClient::GEMINI);
        $cmd = $this->buildProjectCommand($projectRoot, $launcher, $this->buildModelResolver());

        $previousCwd = getcwd();
        $buffering = false;
        try {
            chdir($projectRoot);
            ob_start();
            $buffering = true;
            $cmd->handle(['gemini'], ['developer' => true, 'code' => 'd13', 'effort' => 'high']);
            $output = ob_get_clean();
            $buffering = false;
        } finally {
            if ($buffering && ob_get_level() > 0) {
                ob_end_clean();
            }
            if ($previousCwd !== false) {
                chdir($previousCwd);
            }
        }

        $expected = ['--model', 'gemini-2.5-flash'];
        if ($launcher->lastResolvedModelCliArgs !== $expected) {
            echo "FAIL testGeminiEffortOverridePrintsWarningWithoutEffortArg: expected " . json_encode($expected)
                . ', got ' . json_encode($launcher->lastResolvedModelCliArgs) . "\n";
            return 1;
        }
        if (!str_contains((string) $output, "effort 'high' is not supported by client 'gemini'")) {
            echo "FAIL testGeminiEffortOverridePrintsWarningWithoutEffortArg: expected warning, got {$output}\n";
            return 1;
        }

        echo "OK testGeminiEffortOverridePrintsWarningWithoutEffortArg\n";
        return 0;
    }

    private function testReviewerModeReusesOwnedReviewingEntry(): int
    {
        // Reviewer with an entry already in `reviewing` must reuse it without re-claiming.
        // The launch is mocked; we only assert the framework calls the launcher with the
        // developer WA stored on the entry and updates sessions.json.
        $dir = $this->scratchDir('reviewer-reuse');
        $boardPath = $dir . '/board.yaml';
        $worktreesRoot = $dir . '/worktrees';
        $devWa = $worktreesRoot . '/d04';
        mkdir($worktreesRoot, 0755, true);
        mkdir($devWa, 0755, true);

        $this->writeBoard($boardPath, [
            [
                'kind' => 'feature',
                'stage' => 'reviewing',
                'feature' => self::FEATURE_CRYPTO,
                'developer' => 'd04',
                'reviewer' => 'r01',
                'branch' => 'feat/' . self::FEATURE_CRYPTO,
                'type' => 'feat',
            ],
        ]);

        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $sessionService = new AgentSessionService($dir);
        $reviewerSelector = new AgentReviewerSelector($boardService, $sessionService, $worktreesRoot);

        $launcher = new FakeAgentClientLauncher(AgentClient::CLAUDE);
        $registry = new AgentClientLauncherRegistry();
        $registry->register($launcher);

        $codeService = new AgentCodeService($worktreesRoot, $boardPath, $boardService, $sessionService);
        $contextBuilder = new AgentContextBuilder($dir, $boardPath, $boardService);
        $worktreeService = (new \ReflectionClass(BacklogWorktreeService::class))->newInstanceWithoutConstructor();
        $driver = new FakeSessionDriver();

        $fakeRunner = new FakeBacklogCommandRunner();
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
            new AgentDeveloperSelector($boardService),
            $boardService,
            $driver,
            new FakeProcessSignaler(),
            new FakeProcessRunner(),
            $fakeRunner,
            new NullEntryRebaseService(),
            null,
            $this->buildLaunchPromptResolver(),
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
        if ($driver->lastLaunchCall === null || $driver->lastLaunchCall['cwd'] !== $devWa) {
            $cwd = $driver->lastLaunchCall['cwd'] ?? '<null>';
            echo "FAIL testReviewerModeReusesOwnedReviewingEntry: session driver cwd '{$cwd}', expected '{$devWa}'\n";
            return 1;
        }

        // review-next must NOT be called when the entry is already reviewing for this reviewer.
        if (!empty($fakeRunner->calls)) {
            echo "FAIL testReviewerModeReusesOwnedReviewingEntry: review-next must not be called for an owned reviewing entry, got "
                . json_encode($fakeRunner->calls) . "\n";
            return 1;
        }
        $expectedReviewerResumePrompt = $this->buildLaunchPromptResolver()->resolveStageDecision(AgentRole::REVIEWER, BacklogBoard::STAGE_REVIEWING)->getPrompt();
        if ($launcher->lastInitialPrompt !== $expectedReviewerResumePrompt) {
            echo "FAIL testReviewerModeReusesOwnedReviewingEntry: expected reviewer_resume prompt, got "
                . var_export($launcher->lastInitialPrompt, true) . "\n";
            return 1;
        }

        // The board entry must still be `reviewing` (reused, not transitioned again).
        $reloaded = $boardService->loadBoard($boardPath);
        $stillReviewing = false;
        foreach ($reloaded->getEntries(BacklogBoard::SECTION_ACTIVE) as $entry) {
            if ($entry->getFeature() === self::FEATURE_CRYPTO && $entry->getStage() === 'reviewing') {
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

    private function testReviewerModeCallsReviewNextWhenTakingEntry(): int
    {
        // A reviewer picking up a fresh review entry must delegate the review→reviewing
        // transition to BacklogCommandRunner::reviewNext(), not mutate the board directly.
        $dir = $this->scratchDir('reviewer-takes');
        $boardPath = $dir . '/board.yaml';
        $worktreesRoot = $dir . '/worktrees';
        $devWa = $worktreesRoot . '/d04';
        mkdir($devWa, 0755, true);

        $this->writeBoard($boardPath, [
            [
                'kind' => 'feature',
                'stage' => 'review',
                'feature' => self::FEATURE_CRYPTO,
                'developer' => 'd04',
                'branch' => 'feat/' . self::FEATURE_CRYPTO,
                'type' => 'feat',
            ],
        ]);

        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $sessionService = new AgentSessionService($dir);
        $reviewerSelector = new AgentReviewerSelector($boardService, $sessionService, $worktreesRoot);
        $launcher = new FakeAgentClientLauncher(AgentClient::CLAUDE);
        $registry = new AgentClientLauncherRegistry();
        $registry->register($launcher);

        $fakeRunner = new FakeBacklogCommandRunner();
        // Simulate what review-next does: transition to reviewing so the reloaded board matches.
        $fakeRunner->onReviewNext = static function (string $reviewerCode, string $entryRef) use ($boardService, $boardPath): void {
            $board = $boardService->loadBoard($boardPath);
            foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $entry) {
                if ($entry->getFeature() === self::FEATURE_CRYPTO) {
                    $entry->setStage(BacklogBoard::STAGE_REVIEWING);
                    $entry->setReviewer($reviewerCode);
                    $boardService->saveBoard($board);
                    break;
                }
            }
        };

        $cmd = new AgentStartCommand(
            $dir,
            $worktreesRoot,
            $boardPath,
            $registry,
            new AgentCodeService($worktreesRoot, $boardPath, $boardService, $sessionService),
            $sessionService,
            new AgentContextBuilder($dir, $boardPath, $boardService),
            (new \ReflectionClass(BacklogWorktreeService::class))->newInstanceWithoutConstructor(),
            $reviewerSelector,
            new AgentDeveloperSelector($boardService),
            $boardService,
            new FakeSessionDriver(),
            new FakeProcessSignaler(),
            new FakeProcessRunner(),
            $fakeRunner,
            new NullEntryRebaseService(),
            null,
            $this->buildLaunchPromptResolver(),
        );

        $previousCwd = getcwd();
        try {
            $cmd->handle(['claude'], ['reviewer' => true, 'code' => 'r01']);
        } finally {
            if ($previousCwd !== false) {
                chdir($previousCwd);
            }
        }

        if (count($fakeRunner->calls) < 1 || $fakeRunner->calls[0]['method'] !== 'reviewNext') {
            echo "FAIL testReviewerModeCallsReviewNextWhenTakingEntry: review-next was not called\n";
            return 1;
        }
        if ($fakeRunner->calls[0]['reviewerCode'] !== 'r01' || $fakeRunner->calls[0]['entryRef'] !== self::FEATURE_CRYPTO) {
            echo "FAIL testReviewerModeCallsReviewNextWhenTakingEntry: unexpected args: "
                . json_encode($fakeRunner->calls[0]) . "\n";
            return 1;
        }
        $expectedPrompt = $this->buildLaunchPromptResolver()->resolve(AgentRole::REVIEWER);
        if ($launcher->lastInitialPrompt !== $expectedPrompt) {
            echo "FAIL testReviewerModeCallsReviewNextWhenTakingEntry: expected reviewer initial prompt, got "
                . var_export($launcher->lastInitialPrompt, true) . "\n";
            return 1;
        }

        echo "OK testReviewerModeCallsReviewNextWhenTakingEntry\n";
        return 0;
    }

    private function testManagerStartDoesNotSendInitialPrompt(): int
    {
        $launcher = new FakeAgentClientLauncher(AgentClient::CLAUDE);
        $cmd = $this->buildCommand($launcher);

        $previousCwd = getcwd();
        try {
            $cmd->handle(['claude'], ['manager' => true, 'code' => 'm01']);
        } finally {
            if ($previousCwd !== false) {
                chdir($previousCwd);
            }
        }

        if ($launcher->lastInitialPrompt !== null) {
            echo "FAIL testManagerStartDoesNotSendInitialPrompt: initial prompt must stay null for manager mode\n";
            return 1;
        }

        echo "OK testManagerStartDoesNotSendInitialPrompt\n";
        return 0;
    }

    private function testReviewerModeRollsBackViaCancelWhenPreparationFails(): int
    {
        // When launcher.prepareWorktree() fails after review-next succeeded, the command
        // must call BacklogCommandRunner::reviewCancel(), not mutate the board directly.
        $dir = $this->scratchDir('reviewer-rollback');
        $boardPath = $dir . '/board.yaml';
        $worktreesRoot = $dir . '/worktrees';
        $devWa = $worktreesRoot . '/d04';
        mkdir($devWa, 0755, true);

        $this->writeBoard($boardPath, [
            [
                'kind' => 'feature',
                'stage' => 'review',
                'feature' => self::FEATURE_CRYPTO,
                'developer' => 'd04',
                'branch' => 'feat/' . self::FEATURE_CRYPTO,
                'type' => 'feat',
            ],
        ]);

        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $sessionService = new AgentSessionService($dir);
        $reviewerSelector = new AgentReviewerSelector($boardService, $sessionService, $worktreesRoot);

        $launcher = new FakeAgentClientLauncher(AgentClient::CLAUDE);
        $launcher->prepareException = new \RuntimeException('prepare failed');
        $registry = new AgentClientLauncherRegistry();
        $registry->register($launcher);

        $fakeRunner = new FakeBacklogCommandRunner();
        // Simulate review-next: set stage to reviewing in the board file.
        $fakeRunner->onReviewNext = static function (string $reviewerCode, string $entryRef) use ($boardService, $boardPath): void {
            $board = $boardService->loadBoard($boardPath);
            foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $entry) {
                if ($entry->getFeature() === self::FEATURE_CRYPTO) {
                    $entry->setStage(BacklogBoard::STAGE_REVIEWING);
                    $entry->setReviewer($reviewerCode);
                    $boardService->saveBoard($board);
                    break;
                }
            }
        };
        // Simulate review-cancel: restore stage to review in the board file.
        $fakeRunner->onReviewCancel = static function (string $reviewerCode, string $entryRef) use ($boardService, $boardPath): void {
            $board = $boardService->loadBoard($boardPath);
            foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $entry) {
                if ($entry->getFeature() === self::FEATURE_CRYPTO) {
                    $entry->setStage(BacklogBoard::STAGE_PENDING_REVIEW);
                    $entry->setReviewer(null);
                    $boardService->saveBoard($board);
                    break;
                }
            }
        };

        $cmd = new AgentStartCommand(
            $dir,
            $worktreesRoot,
            $boardPath,
            $registry,
            new AgentCodeService($worktreesRoot, $boardPath, $boardService, $sessionService),
            $sessionService,
            new AgentContextBuilder($dir, $boardPath, $boardService),
            (new \ReflectionClass(BacklogWorktreeService::class))->newInstanceWithoutConstructor(),
            $reviewerSelector,
            new AgentDeveloperSelector($boardService),
            $boardService,
            new FakeSessionDriver(),
            new FakeProcessSignaler(),
            new FakeProcessRunner(),
            $fakeRunner,
            new NullEntryRebaseService(),
            null,
            $this->buildLaunchPromptResolver(),
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
            echo "FAIL testReviewerModeRollsBackViaCancelWhenPreparationFails: expected preparation failure\n";
            return 1;
        }

        // Verify review-cancel was called (delegation, not direct mutation)
        $cancelCall = null;
        foreach ($fakeRunner->calls as $call) {
            if ($call['method'] === 'reviewCancel') {
                $cancelCall = $call;
                break;
            }
        }
        if ($cancelCall === null) {
            echo "FAIL testReviewerModeRollsBackViaCancelWhenPreparationFails: review-cancel was not called\n";
            return 1;
        }
        if ($cancelCall['reviewerCode'] !== 'r01' || $cancelCall['entryRef'] !== self::FEATURE_CRYPTO) {
            echo "FAIL testReviewerModeRollsBackViaCancelWhenPreparationFails: unexpected cancel args: "
                . json_encode($cancelCall) . "\n";
            return 1;
        }

        // Board must be back at stage=review (simulated by fakeRunner callbacks)
        $reloaded = $boardService->loadBoard($boardPath);
        $entry = $reloaded->getEntries(BacklogBoard::SECTION_ACTIVE)[0] ?? null;
        if ($entry === null || $entry->getStage() !== BacklogBoard::STAGE_PENDING_REVIEW || $entry->getReviewer() !== null) {
            echo "FAIL testReviewerModeRollsBackViaCancelWhenPreparationFails: expected stage=review and reviewer cleared\n";
            return 1;
        }

        echo "OK testReviewerModeRollsBackViaCancelWhenPreparationFails\n";
        return 0;
    }

    private function testReviewerStartsSuccessfullyWhenDeveloperIsActive(): int
    {
        // Reviewer must start on a WA that already has an active developer session — no exception.
        $dir = $this->scratchDir('reviewer-with-dev');
        $boardPath = $dir . '/board.yaml';
        $worktreesRoot = $dir . '/worktrees';
        $devWa = $worktreesRoot . '/d04';
        mkdir($devWa, 0755, true);

        $this->writeBoard($boardPath, [
            [
                'kind' => 'feature',
                'stage' => 'review',
                'feature' => self::FEATURE_CRYPTO,
                'developer' => 'd04',
                'branch' => 'feat/' . self::FEATURE_CRYPTO,
                'type' => 'feat',
            ],
        ]);

        // Developer d04 has an active session in the target WA.
        $this->writeSessionsJson($dir, [
            'd04' => [
                'client' => 'claude',
                'role' => 'developer',
                'pid' => 11111,
                'worktree' => $devWa,
                'started_at' => '2026-01-01T00:00:00+00:00',
                'last_seen_at' => '2026-01-01T00:00:00+00:00',
                'session_id' => null,
            ],
        ]);

        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $sessionService = new AgentSessionService($dir);
        $reviewerSelector = new AgentReviewerSelector($boardService, $sessionService, $worktreesRoot);
        $launcher = new FakeAgentClientLauncher(AgentClient::CLAUDE);
        $registry = new AgentClientLauncherRegistry();
        $registry->register($launcher);

        $fakeRunner = new FakeBacklogCommandRunner();
        $fakeRunner->onReviewNext = static function (string $reviewerCode, string $entryRef) use ($boardService, $boardPath): void {
            $board = $boardService->loadBoard($boardPath);
            foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $entry) {
                if ($entry->getFeature() === self::FEATURE_CRYPTO) {
                    $entry->setStage(BacklogBoard::STAGE_REVIEWING);
                    $entry->setReviewer($reviewerCode);
                    $boardService->saveBoard($board);
                    break;
                }
            }
        };

        $cmd = new AgentStartCommand(
            $dir,
            $worktreesRoot,
            $boardPath,
            $registry,
            new AgentCodeService($worktreesRoot, $boardPath, $boardService, $sessionService),
            $sessionService,
            new AgentContextBuilder($dir, $boardPath, $boardService),
            (new \ReflectionClass(BacklogWorktreeService::class))->newInstanceWithoutConstructor(),
            $reviewerSelector,
            new AgentDeveloperSelector($boardService),
            $boardService,
            new FakeSessionDriver(),
            new FakeProcessSignaler(),
            new FakeProcessRunner(),
            $fakeRunner,
            new NullEntryRebaseService(),
            null,
            $this->buildLaunchPromptResolver(),
        );

        $previousCwd = getcwd();
        $threw = false;
        try {
            $cmd->handle(['claude'], ['reviewer' => true, 'code' => 'r01']);
        } catch (\Throwable $e) {
            $threw = true;
            echo "FAIL testReviewerStartsSuccessfullyWhenDeveloperIsActive: unexpected exception: "
                . get_class($e) . ': ' . $e->getMessage() . "\n";
        } finally {
            if ($previousCwd !== false) {
                chdir($previousCwd);
            }
        }

        if ($threw) {
            return 1;
        }
        if ($launcher->lastLaunchedWorktree !== $devWa) {
            echo "FAIL testReviewerStartsSuccessfullyWhenDeveloperIsActive: launcher did not launch on '{$devWa}'\n";
            return 1;
        }

        echo "OK testReviewerStartsSuccessfullyWhenDeveloperIsActive\n";
        return 0;
    }

    private function testReviewerRefusedWhenAnotherReviewerIsActive(): int
    {
        // Another reviewer (r99) already holds the WA — r01 must be refused without --force.
        $dir = $this->scratchDir('reviewer-conflict');
        $boardPath = $dir . '/board.yaml';
        $worktreesRoot = $dir . '/worktrees';
        $devWa = $worktreesRoot . '/d04';
        mkdir($devWa, 0755, true);

        $this->writeBoard($boardPath, [
            [
                'kind' => 'feature',
                'stage' => 'review',
                'feature' => self::FEATURE_CRYPTO,
                'developer' => 'd04',
                'branch' => 'feat/' . self::FEATURE_CRYPTO,
                'type' => 'feat',
            ],
        ]);

        // Reviewer r99 has an active session in the target WA.
        $this->writeSessionsJson($dir, [
            'r99' => [
                'client' => 'claude',
                'role' => 'reviewer',
                'pid' => 99999,
                'worktree' => $devWa,
                'started_at' => '2026-01-01T00:00:00+00:00',
                'last_seen_at' => '2026-01-01T00:00:00+00:00',
                'session_id' => null,
            ],
        ]);

        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $sessionService = new AgentSessionService($dir);
        $reviewerSelector = new AgentReviewerSelector($boardService, $sessionService, $worktreesRoot);
        $launcher = new FakeAgentClientLauncher(AgentClient::CLAUDE);
        $registry = new AgentClientLauncherRegistry();
        $registry->register($launcher);

        $fakeRunner = new FakeBacklogCommandRunner();
        $fakeRunner->onReviewNext = static function (string $reviewerCode, string $entryRef) use ($boardService, $boardPath): void {
            $board = $boardService->loadBoard($boardPath);
            foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $entry) {
                if ($entry->getFeature() === self::FEATURE_CRYPTO) {
                    $entry->setStage(BacklogBoard::STAGE_REVIEWING);
                    $entry->setReviewer($reviewerCode);
                    $boardService->saveBoard($board);
                    break;
                }
            }
        };
        $fakeRunner->onReviewCancel = static function (string $reviewerCode, string $entryRef) use ($boardService, $boardPath): void {
            $board = $boardService->loadBoard($boardPath);
            foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $entry) {
                if ($entry->getFeature() === self::FEATURE_CRYPTO) {
                    $entry->setStage(BacklogBoard::STAGE_PENDING_REVIEW);
                    $entry->setReviewer(null);
                    $boardService->saveBoard($board);
                    break;
                }
            }
        };

        $cmd = new AgentStartCommand(
            $dir,
            $worktreesRoot,
            $boardPath,
            $registry,
            new AgentCodeService($worktreesRoot, $boardPath, $boardService, $sessionService),
            $sessionService,
            new AgentContextBuilder($dir, $boardPath, $boardService),
            (new \ReflectionClass(BacklogWorktreeService::class))->newInstanceWithoutConstructor(),
            $reviewerSelector,
            new AgentDeveloperSelector($boardService),
            $boardService,
            new FakeSessionDriver(),
            new FakeProcessSignaler(),
            new FakeProcessRunner(),
            $fakeRunner,
            new NullEntryRebaseService(),
            null,
            $this->buildLaunchPromptResolver(),
        );

        // Use --developer=d04 so the entry is explicitly targeted (bypassing autoSelect's
        // early-exit when the WA is already claimed) and the reviewer-vs-reviewer guard fires.
        $previousCwd = getcwd();
        $threw = false;
        try {
            $cmd->handle(['claude'], ['reviewer' => true, 'code' => 'r01', 'developer' => 'd04']);
        } catch (ActiveSessionException) {
            $threw = true;
        } catch (\Throwable $other) {
            echo "FAIL testReviewerRefusedWhenAnotherReviewerIsActive: expected ActiveSessionException, got "
                . get_class($other) . ': ' . $other->getMessage() . "\n";
            return 1;
        } finally {
            if ($previousCwd !== false) {
                chdir($previousCwd);
            }
        }

        if (!$threw) {
            echo "FAIL testReviewerRefusedWhenAnotherReviewerIsActive: expected ActiveSessionException\n";
            return 1;
        }

        echo "OK testReviewerRefusedWhenAnotherReviewerIsActive\n";
        return 0;
    }

    private function testDeveloperResetRefusesDirtyWorktree(): int
    {
        $projectRoot = $this->createGitProject('reset-dirty');
        $worktreesRoot = $projectRoot . '/.agent-worktrees';
        $boardPath = BacklogPaths::boardPath($projectRoot);
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
            new AgentCodeService($worktreesRoot, $boardPath, $boardService, $sessionService),
            $sessionService,
            new AgentContextBuilder($projectRoot, $boardPath, $boardService),
            $this->buildRealWorktreeService($projectRoot, $worktreesRoot, $boardService),
            new AgentReviewerSelector($boardService, $sessionService, $worktreesRoot),
            new AgentDeveloperSelector($boardService),
            $boardService,
            new FakeSessionDriver(),
            new FakeProcessSignaler(),
            $shellRunner,
            new FakeBacklogCommandRunner(),
            new NullEntryRebaseService(),
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
        $boardPath = BacklogPaths::boardPath($projectRoot);
        $worktree = $worktreesRoot . '/d06';
        $this->writeBoard($boardPath, [
            [
                'kind' => 'feature',
                'stage' => 'development',
                'feature' => 'reset-feature',
                'developer' => 'd06',
                'branch' => 'feat/reset-feature',
            ],
        ]);
        $this->runShell('git -C ' . escapeshellarg($projectRoot) . ' worktree add --detach ' . escapeshellarg($worktree) . ' HEAD');

        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $sessionService = new AgentSessionService($projectRoot);
        $launcher = new FakeAgentClientLauncher(AgentClient::CLAUDE);
        $registry = new AgentClientLauncherRegistry();
        $registry->register($launcher);
        $driver = new FakeSessionDriver();

        $cmd = new AgentStartCommand(
            $projectRoot,
            $worktreesRoot,
            $boardPath,
            $registry,
            new AgentCodeService($worktreesRoot, $boardPath, $boardService, $sessionService),
            $sessionService,
            new AgentContextBuilder($projectRoot, $boardPath, $boardService),
            $this->buildRealWorktreeService($projectRoot, $worktreesRoot, $boardService),
            new AgentReviewerSelector($boardService, $sessionService, $worktreesRoot),
            new AgentDeveloperSelector($boardService),
            $boardService,
            $driver,
            new FakeProcessSignaler(),
            new FakeProcessRunner(),
            new FakeBacklogCommandRunner(),
            new NullEntryRebaseService(),
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
        if ($driver->lastLaunchCall === null || $driver->lastLaunchCall['cwd'] !== $worktree) {
            $cwd = $driver->lastLaunchCall['cwd'] ?? '<null>';
            echo "FAIL testDeveloperResetRemovesAndRecreatesCleanWorktree: session driver cwd '{$cwd}', expected '{$worktree}'\n";
            return 1;
        }

        echo "OK testDeveloperResetRemovesAndRecreatesCleanWorktree\n";
        return 0;
    }

    private function testLaunchKeepsSessionEntryWhenDriverReportsDetach(): int
    {
        // After attach-session exits with the tmux session still alive (detach scenario),
        // the sessions.json entry must be kept so stop/list/status/resume can still reach the session.
        $projectRoot = $this->createGitProject('detach-keep');
        $worktreesRoot = $projectRoot . '/.agent-worktrees';
        $boardPath = BacklogPaths::boardPath($projectRoot);
        $worktree = $worktreesRoot . '/d07';
        $this->writeBoard($boardPath, [
            [
                'kind' => 'feature',
                'stage' => 'development',
                'feature' => 'detach-feature',
                'developer' => 'd07',
                'branch' => 'feat/detach-feature',
            ],
        ]);
        $this->runShell('git -C ' . escapeshellarg($projectRoot) . ' worktree add --detach ' . escapeshellarg($worktree) . ' HEAD');

        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $sessionService = new AgentSessionService($projectRoot);
        $launcher = new FakeAgentClientLauncher(AgentClient::CLAUDE);
        $registry = new AgentClientLauncherRegistry();
        $registry->register($launcher);

        // Simulate tmux detach: after launch() returns, isAlive() returns true for 'd07'.
        $driver = new FakeSessionDriver();
        $driver->setAlive('d07', true);

        $cmd = new AgentStartCommand(
            $projectRoot,
            $worktreesRoot,
            $boardPath,
            $registry,
            new AgentCodeService($worktreesRoot, $boardPath, $boardService, $sessionService),
            $sessionService,
            new AgentContextBuilder($projectRoot, $boardPath, $boardService),
            $this->buildRealWorktreeService($projectRoot, $worktreesRoot, $boardService),
            new AgentReviewerSelector($boardService, $sessionService, $worktreesRoot),
            new AgentDeveloperSelector($boardService),
            $boardService,
            $driver,
            new FakeProcessSignaler(),
            new FakeProcessRunner(),
            new FakeBacklogCommandRunner(),
            new NullEntryRebaseService(),
        );

        $previousCwd = getcwd();
        $exitCode = null;
        try {
            chdir($projectRoot);
            ob_start();
            $exitCode = $cmd->handle(['claude'], ['developer' => true, 'code' => 'd07']);
            $output = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            echo "FAIL testLaunchKeepsSessionEntryWhenDriverReportsDetach: unexpected " . get_class($e) . ': ' . $e->getMessage() . "\n";
            return 1;
        } finally {
            if ($previousCwd !== false) {
                chdir($previousCwd);
            }
        }

        if ($exitCode !== 0) {
            echo "FAIL testLaunchKeepsSessionEntryWhenDriverReportsDetach: expected exit code 0, got {$exitCode}\n";
            return 1;
        }

        if ($sessionService->get('d07') === null) {
            echo "FAIL testLaunchKeepsSessionEntryWhenDriverReportsDetach: sessions.json entry was removed on detach — it must be kept\n";
            return 1;
        }

        if (!str_contains((string) $output, 'Session detached')) {
            echo "FAIL testLaunchKeepsSessionEntryWhenDriverReportsDetach: expected detach message in output\n";
            return 1;
        }

        echo "OK testLaunchKeepsSessionEntryWhenDriverReportsDetach\n";
        return 0;
    }

    private function testDeveloperAutoPicksFirstQueuedTask(): int
    {
        // When the developer has no active entry and the todo has a queued task,
        // start --developer must call start with the task's entry ref.
        $projectRoot = $this->createGitProject('dev-auto-pick');
        $worktreesRoot = $projectRoot . '/.agent-worktrees';
        $boardPath = BacklogPaths::boardPath($projectRoot);

        $this->writeBoard($boardPath, [], [
            ['feature' => self::FEATURE_MY, 'type' => 'feat', 'title' => 'Auto-pick task'],
        ]);

        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $sessionService = new AgentSessionService($projectRoot);
        $launcher = new FakeAgentClientLauncher(AgentClient::CLAUDE);
        $registry = new AgentClientLauncherRegistry();
        $registry->register($launcher);
        $fakeRunner = new FakeBacklogCommandRunner();
        $driver = new FakeSessionDriver();

        $cmd = new AgentStartCommand(
            $projectRoot,
            $worktreesRoot,
            $boardPath,
            $registry,
            new AgentCodeService($worktreesRoot, $boardPath, $boardService, $sessionService),
            $sessionService,
            new AgentContextBuilder($projectRoot, $boardPath, $boardService),
            $this->buildRealWorktreeService($projectRoot, $worktreesRoot, $boardService),
            new AgentReviewerSelector($boardService, $sessionService, $worktreesRoot),
            new AgentDeveloperSelector($boardService),
            $boardService,
            $driver,
            new FakeProcessSignaler(),
            new FakeProcessRunner(),
            $fakeRunner,
            new NullEntryRebaseService(),
            null,
            $this->buildLaunchPromptResolver(),
        );

        $previousCwd = getcwd();
        try {
            chdir($projectRoot);
            $cmd->handle(['claude'], ['developer' => true, 'code' => 'd08']);
        } catch (\Throwable $e) {
            echo "FAIL testDeveloperAutoPicksFirstQueuedTask: unexpected " . get_class($e) . ': ' . $e->getMessage() . "\n";
            return 1;
        } finally {
            if ($previousCwd !== false) {
                chdir($previousCwd);
            }
        }

        $workStartCall = null;
        foreach ($fakeRunner->calls as $call) {
            if ($call['method'] === 'workStart') {
                $workStartCall = $call;
                break;
            }
        }
        if ($workStartCall === null) {
            echo "FAIL testDeveloperAutoPicksFirstQueuedTask: start was not called\n";
            return 1;
        }
        if ($workStartCall['developerCode'] !== 'd08' || $workStartCall['entryRef'] !== self::FEATURE_MY) {
            echo "FAIL testDeveloperAutoPicksFirstQueuedTask: unexpected start args: "
                . json_encode($workStartCall) . "\n";
            return 1;
        }
        $expectedPrompt = $this->buildLaunchPromptResolver()->resolve(AgentRole::DEVELOPER);
        if ($launcher->lastInitialPrompt !== $expectedPrompt) {
            echo "FAIL testDeveloperAutoPicksFirstQueuedTask: expected developer initial prompt, got "
                . var_export($launcher->lastInitialPrompt, true) . "\n";
            return 1;
        }

        echo "OK testDeveloperAutoPicksFirstQueuedTask\n";
        return 0;
    }

    private function testDeveloperRefusesWhenTodoEmpty(): int
    {
        // When the todo list is empty and the developer has no active entry,
        // start --developer must refuse with a clear error and must not call start.
        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $sessionService = new AgentSessionService($this->tmpDir);
        $launcher = new FakeAgentClientLauncher(AgentClient::CLAUDE);
        $registry = new AgentClientLauncherRegistry();
        $registry->register($launcher);
        $fakeRunner = new FakeBacklogCommandRunner();

        $boardPath = $this->tmpDir . '/board-empty.yaml';
        $this->writeBoard($boardPath, []);

        $cmd = new AgentStartCommand(
            $this->tmpDir,
            $this->tmpDir . '/worktrees',
            $boardPath,
            $registry,
            new AgentCodeService($this->tmpDir . '/worktrees', $boardPath, $boardService, $sessionService),
            $sessionService,
            new AgentContextBuilder($this->tmpDir, $boardPath, $boardService),
            (new \ReflectionClass(BacklogWorktreeService::class))->newInstanceWithoutConstructor(),
            new AgentReviewerSelector($boardService, $sessionService, $this->tmpDir . '/worktrees'),
            new AgentDeveloperSelector($boardService),
            $boardService,
            new FakeSessionDriver(),
            new FakeProcessSignaler(),
            new FakeProcessRunner(),
            $fakeRunner,
            new NullEntryRebaseService(),
            null,
            $this->buildLaunchPromptResolver(),
        );

        $threw = false;
        try {
            $cmd->handle(['claude'], ['developer' => true, 'code' => 'd08']);
        } catch (\RuntimeException $e) {
            $threw = str_contains($e->getMessage(), 'No queued task available for d08');
        }

        if (!$threw) {
            echo "FAIL testDeveloperRefusesWhenTodoEmpty: expected empty-todo error\n";
            return 1;
        }
        foreach ($fakeRunner->calls as $call) {
            if ($call['method'] === 'workStart') {
                echo "FAIL testDeveloperRefusesWhenTodoEmpty: start must not be called\n";
                return 1;
            }
        }

        echo "OK testDeveloperRefusesWhenTodoEmpty\n";
        return 0;
    }

    private function testDeveloperSkipsAutoPickWhenAlreadyHasActiveEntry(): int
    {
        // When the developer already has an active entry, start must not call start again.
        $projectRoot = $this->createGitProject('dev-skip-pick');
        $worktreesRoot = $projectRoot . '/.agent-worktrees';
        $boardPath = BacklogPaths::boardPath($projectRoot);

        $this->writeBoard($boardPath, [
            [
                'kind' => 'feature',
                'stage' => 'development',
                'feature' => 'active-feature',
                'developer' => 'd09',
                'branch' => 'feat/active-feature',
            ],
        ], [
            ['feature' => 'queued-feature', 'type' => 'feat', 'title' => 'A queued task'],
        ]);

        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $sessionService = new AgentSessionService($projectRoot);
        $launcher = new FakeAgentClientLauncher(AgentClient::CLAUDE);
        $registry = new AgentClientLauncherRegistry();
        $registry->register($launcher);
        $fakeRunner = new FakeBacklogCommandRunner();
        $driver = new FakeSessionDriver();

        $cmd = new AgentStartCommand(
            $projectRoot,
            $worktreesRoot,
            $boardPath,
            $registry,
            new AgentCodeService($worktreesRoot, $boardPath, $boardService, $sessionService),
            $sessionService,
            new AgentContextBuilder($projectRoot, $boardPath, $boardService),
            $this->buildRealWorktreeService($projectRoot, $worktreesRoot, $boardService),
            new AgentReviewerSelector($boardService, $sessionService, $worktreesRoot),
            new AgentDeveloperSelector($boardService),
            $boardService,
            $driver,
            new FakeProcessSignaler(),
            new FakeProcessRunner(),
            $fakeRunner,
            new NullEntryRebaseService(),
            null,
            $this->buildLaunchPromptResolver(),
        );

        $previousCwd = getcwd();
        try {
            chdir($projectRoot);
            $cmd->handle(['claude'], ['developer' => true, 'code' => 'd09']);
        } catch (\Throwable $e) {
            echo "FAIL testDeveloperSkipsAutoPickWhenAlreadyHasActiveEntry: unexpected " . get_class($e) . ': ' . $e->getMessage() . "\n";
            return 1;
        } finally {
            if ($previousCwd !== false) {
                chdir($previousCwd);
            }
        }

        foreach ($fakeRunner->calls as $call) {
            if ($call['method'] === 'workStart') {
                echo "FAIL testDeveloperSkipsAutoPickWhenAlreadyHasActiveEntry: start must not be called when entry already active\n";
                return 1;
            }
        }
        $expectedResumePrompt = $this->buildLaunchPromptResolver()->resolveStageDecision(AgentRole::DEVELOPER, BacklogBoard::STAGE_IN_PROGRESS)->getPrompt();
        if ($launcher->lastInitialPrompt !== $expectedResumePrompt) {
            echo "FAIL testDeveloperSkipsAutoPickWhenAlreadyHasActiveEntry: expected developer_resume prompt, got "
                . var_export($launcher->lastInitialPrompt, true) . "\n";
            return 1;
        }

        echo "OK testDeveloperSkipsAutoPickWhenAlreadyHasActiveEntry\n";
        return 0;
    }

    private function testDeveloperRollsBackViaEntryReleaseWhenPreparationFails(): int
    {
        // When launcher.prepareWorktree() fails after start succeeded, the command
        // must call BacklogCommandRunner::entryRelease() to roll back the taken task.
        $projectRoot = $this->createGitProject('dev-rollback');
        $worktreesRoot = $projectRoot . '/.agent-worktrees';
        $boardPath = BacklogPaths::boardPath($projectRoot);

        $this->writeBoard($boardPath, [], [
            ['feature' => self::FEATURE_ROLLBACK, 'type' => 'feat', 'title' => 'Task to auto-pick'],
        ]);

        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $sessionService = new AgentSessionService($projectRoot);

        $launcher = new FakeAgentClientLauncher(AgentClient::CLAUDE);
        $launcher->prepareException = new \RuntimeException('prepare failed');
        $registry = new AgentClientLauncherRegistry();
        $registry->register($launcher);

        $fakeRunner = new FakeBacklogCommandRunner();

        $cmd = new AgentStartCommand(
            $projectRoot,
            $worktreesRoot,
            $boardPath,
            $registry,
            new AgentCodeService($worktreesRoot, $boardPath, $boardService, $sessionService),
            $sessionService,
            new AgentContextBuilder($projectRoot, $boardPath, $boardService),
            $this->buildRealWorktreeService($projectRoot, $worktreesRoot, $boardService),
            new AgentReviewerSelector($boardService, $sessionService, $worktreesRoot),
            new AgentDeveloperSelector($boardService),
            $boardService,
            new FakeSessionDriver(),
            new FakeProcessSignaler(),
            new FakeProcessRunner(),
            $fakeRunner,
            new NullEntryRebaseService(),
        );

        $previousCwd = getcwd();
        $threw = false;
        try {
            chdir($projectRoot);
            $cmd->handle(['claude'], ['developer' => true, 'code' => 'd10']);
        } catch (\RuntimeException $e) {
            $threw = str_contains($e->getMessage(), 'prepare failed');
        } finally {
            if ($previousCwd !== false) {
                chdir($previousCwd);
            }
        }

        if (!$threw) {
            echo "FAIL testDeveloperRollsBackViaEntryReleaseWhenPreparationFails: expected preparation failure\n";
            return 1;
        }

        $releaseCall = null;
        foreach ($fakeRunner->calls as $call) {
            if ($call['method'] === 'entryRelease') {
                $releaseCall = $call;
                break;
            }
        }
        if ($releaseCall === null) {
            echo "FAIL testDeveloperRollsBackViaEntryReleaseWhenPreparationFails: release was not called\n";
            return 1;
        }
        if ($releaseCall['developerCode'] !== 'd10' || $releaseCall['entryRef'] !== self::FEATURE_ROLLBACK) {
            echo "FAIL testDeveloperRollsBackViaEntryReleaseWhenPreparationFails: unexpected release args: "
                . json_encode($releaseCall) . "\n";
            return 1;
        }

        echo "OK testDeveloperRollsBackViaEntryReleaseWhenPreparationFails\n";
        return 0;
    }

    private function testHandleRestoresCwdOnSuccess(): int
    {
        // handle() does chdir($worktree) before launch; the cwd must be restored to whatever
        // it was on entry when handle() returns, so subsequent shell commands in the caller
        // do not inherit a stale (potentially deleted) directory.
        $projectRoot = $this->createGitProject('cwd-restore-success');
        $worktreesRoot = $projectRoot . '/.agent-worktrees';
        $boardPath = BacklogPaths::boardPath($projectRoot);
        $worktree = $worktreesRoot . '/d14';

        $this->writeBoard($boardPath, [
            [
                'kind' => 'feature',
                'stage' => 'development',
                'feature' => 'cwd-feature',
                'developer' => 'd14',
                'branch' => 'feat/cwd-feature',
            ],
        ]);
        $this->runShell('git -C ' . escapeshellarg($projectRoot) . ' worktree add --detach ' . escapeshellarg($worktree) . ' HEAD');

        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $sessionService = new AgentSessionService($projectRoot);
        $launcher = new FakeAgentClientLauncher(AgentClient::CLAUDE);
        $registry = new AgentClientLauncherRegistry();
        $registry->register($launcher);

        $cmd = new AgentStartCommand(
            $projectRoot,
            $worktreesRoot,
            $boardPath,
            $registry,
            new AgentCodeService($worktreesRoot, $boardPath, $boardService, $sessionService),
            $sessionService,
            new AgentContextBuilder($projectRoot, $boardPath, $boardService),
            $this->buildRealWorktreeService($projectRoot, $worktreesRoot, $boardService),
            new AgentReviewerSelector($boardService, $sessionService, $worktreesRoot),
            new AgentDeveloperSelector($boardService),
            $boardService,
            new FakeSessionDriver(),
            new FakeProcessSignaler(),
            new FakeProcessRunner(),
            new FakeBacklogCommandRunner(),
            new NullEntryRebaseService(),
        );

        $cwdBefore = getcwd();
        chdir($projectRoot);
        $cwdEntry = getcwd();
        $cwdAfter = null;
        try {
            $cmd->handle(['claude'], ['developer' => true, 'code' => 'd14']);
            $cwdAfter = getcwd();
        } catch (\Throwable $e) {
            echo "FAIL testHandleRestoresCwdOnSuccess: unexpected " . get_class($e) . ': ' . $e->getMessage() . "\n";
            return 1;
        } finally {
            if ($cwdBefore !== false) {
                chdir($cwdBefore);
            }
        }

        if ($cwdAfter !== $cwdEntry) {
            echo "FAIL testHandleRestoresCwdOnSuccess: cwd after handle() is '{$cwdAfter}', expected '{$cwdEntry}'\n";
            return 1;
        }

        echo "OK testHandleRestoresCwdOnSuccess\n";
        return 0;
    }

    private function testHandleRestoresCwdWhenWorktreeDeletedDuringLaunch(): int
    {
        // Simulates the scenario where a concurrent worktree-clean removes the WA directory
        // while the session is running. After handle() returns, PHP's cwd must be the original
        // cwd — not the deleted worktree — so no "getcwd() failed" noise is emitted to the terminal.
        $projectRoot = $this->createGitProject('cwd-restore-deleted-wa');
        $worktreesRoot = $projectRoot . '/.agent-worktrees';
        $boardPath = BacklogPaths::boardPath($projectRoot);
        $worktree = $worktreesRoot . '/d15';

        $this->writeBoard($boardPath, [
            [
                'kind' => 'feature',
                'stage' => 'development',
                'feature' => 'cwd-del-feature',
                'developer' => 'd15',
                'branch' => 'feat/cwd-del-feature',
            ],
        ]);
        $this->runShell('git -C ' . escapeshellarg($projectRoot) . ' worktree add --detach ' . escapeshellarg($worktree) . ' HEAD');

        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $sessionService = new AgentSessionService($projectRoot);
        $launcher = new FakeAgentClientLauncher(AgentClient::CLAUDE);
        $registry = new AgentClientLauncherRegistry();
        $registry->register($launcher);

        $driver = new FakeSessionDriver();
        // Simulate a concurrent worktree-clean that removes the WA directory during the session.
        $driver->onLaunchHook = function () use ($worktree): void {
            $this->rmdir($worktree);
        };

        $cmd = new AgentStartCommand(
            $projectRoot,
            $worktreesRoot,
            $boardPath,
            $registry,
            new AgentCodeService($worktreesRoot, $boardPath, $boardService, $sessionService),
            $sessionService,
            new AgentContextBuilder($projectRoot, $boardPath, $boardService),
            $this->buildRealWorktreeService($projectRoot, $worktreesRoot, $boardService),
            new AgentReviewerSelector($boardService, $sessionService, $worktreesRoot),
            new AgentDeveloperSelector($boardService),
            $boardService,
            $driver,
            new FakeProcessSignaler(),
            new FakeProcessRunner(),
            new FakeBacklogCommandRunner(),
            new NullEntryRebaseService(),
        );

        $cwdBefore = getcwd();
        chdir($projectRoot);
        $cwdEntry = getcwd();
        $cwdAfter = null;
        try {
            $cmd->handle(['claude'], ['developer' => true, 'code' => 'd15']);
            $cwdAfter = getcwd();
        } catch (\Throwable $e) {
            echo "FAIL testHandleRestoresCwdWhenWorktreeDeletedDuringLaunch: unexpected " . get_class($e) . ': ' . $e->getMessage() . "\n";
            return 1;
        } finally {
            if ($cwdBefore !== false) {
                chdir($cwdBefore);
            }
        }

        if ($cwdAfter !== $cwdEntry) {
            echo "FAIL testHandleRestoresCwdWhenWorktreeDeletedDuringLaunch: cwd after handle() is '{$cwdAfter}', expected '{$cwdEntry}'\n";
            return 1;
        }

        echo "OK testHandleRestoresCwdWhenWorktreeDeletedDuringLaunch\n";
        return 0;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeSessionsJson(string $projectRoot, array $data): void
    {
        $dir = $projectRoot . '/local/tmp';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($dir . '/agent-sessions.json', json_encode($data));
    }

    private function testDeveloperStageReviewRefuses(): int
    {
        // Developer with entry at stage=review must get exit code 1 with a refusal message.
        $projectRoot = $this->createGitProject('dev-stage-review');
        $this->writeBoard(BacklogPaths::boardPath($projectRoot), [
            [
                'kind' => 'feature',
                'stage' => 'review',
                'feature' => 'review-feature',
                'developer' => 'd20',
                'branch' => 'feat/review-feature',
                'type' => 'tech',
            ],
        ]);

        $launcher = new FakeAgentClientLauncher(AgentClient::CLAUDE);
        $cmd = $this->buildProjectCommand($projectRoot, $launcher);

        $previousCwd = getcwd();
        $exitCode = null;
        try {
            chdir($projectRoot);
            ob_start();
            $exitCode = $cmd->handle(['claude'], ['developer' => true, 'code' => 'd20']);
            ob_end_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            echo "FAIL testDeveloperStageReviewRefuses: unexpected exception: " . get_class($e) . ': ' . $e->getMessage() . "\n";
            return 1;
        } finally {
            if ($previousCwd !== false) {
                chdir($previousCwd);
            }
        }

        if ($exitCode !== 1) {
            echo "FAIL testDeveloperStageReviewRefuses: expected exit code 1, got {$exitCode}\n";
            return 1;
        }
        if ($launcher->lastLaunchedWorktree !== null) {
            echo "FAIL testDeveloperStageReviewRefuses: launcher must not be called on refusal\n";
            return 1;
        }

        echo "OK testDeveloperStageReviewRefuses\n";
        return 0;
    }

    private function testDeveloperStageReviewingRefuses(): int
    {
        // Developer with entry at stage=reviewing must get exit code 1 with a refusal message.
        $projectRoot = $this->createGitProject('dev-stage-reviewing');
        $this->writeBoard(BacklogPaths::boardPath($projectRoot), [
            [
                'kind' => 'feature',
                'stage' => 'reviewing',
                'feature' => 'reviewing-feature',
                'developer' => 'd21',
                'branch' => 'feat/reviewing-feature',
                'type' => 'tech',
            ],
        ]);

        $launcher = new FakeAgentClientLauncher(AgentClient::CLAUDE);
        $cmd = $this->buildProjectCommand($projectRoot, $launcher);

        $previousCwd = getcwd();
        $exitCode = null;
        try {
            chdir($projectRoot);
            ob_start();
            $exitCode = $cmd->handle(['claude'], ['developer' => true, 'code' => 'd21']);
            ob_end_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            echo "FAIL testDeveloperStageReviewingRefuses: unexpected exception: " . get_class($e) . ': ' . $e->getMessage() . "\n";
            return 1;
        } finally {
            if ($previousCwd !== false) {
                chdir($previousCwd);
            }
        }

        if ($exitCode !== 1) {
            echo "FAIL testDeveloperStageReviewingRefuses: expected exit code 1, got {$exitCode}\n";
            return 1;
        }
        if ($launcher->lastLaunchedWorktree !== null) {
            echo "FAIL testDeveloperStageReviewingRefuses: launcher must not be called on refusal\n";
            return 1;
        }

        echo "OK testDeveloperStageReviewingRefuses\n";
        return 0;
    }

    private function testDeveloperStageApprovedUpToDateSkipsAgent(): int
    {
        // Developer with entry at stage=approved and rebase service reporting up-to-date
        // must exit 0 without launching the agent.
        $projectRoot = $this->createGitProject('dev-approved-up-to-date');
        $worktreesRoot = $projectRoot . '/.agent-worktrees';
        $boardPath = BacklogPaths::boardPath($projectRoot);
        $worktree = $worktreesRoot . '/d22';

        $this->writeBoard($boardPath, [
            [
                'kind' => 'feature',
                'stage' => 'approved',
                'feature' => 'approved-feature',
                'developer' => 'd22',
                'branch' => 'feat/approved-feature',
                'type' => 'tech',
            ],
        ]);
        $this->runShell('git -C ' . escapeshellarg($projectRoot) . ' worktree add --detach ' . escapeshellarg($worktree) . ' HEAD');

        $launcher = new FakeAgentClientLauncher(AgentClient::CLAUDE);
        $registry = new AgentClientLauncherRegistry();
        $registry->register($launcher);

        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $sessionService = new AgentSessionService($projectRoot);
        $fakeRebase = new FakeEntryRebaseService(EntryRebaseResult::upToDate('origin/main'));

        $cmd = new AgentStartCommand(
            $projectRoot,
            $worktreesRoot,
            $boardPath,
            $registry,
            new AgentCodeService($worktreesRoot, $boardPath, $boardService, $sessionService),
            $sessionService,
            new AgentContextBuilder($projectRoot, $boardPath, $boardService),
            $this->buildRealWorktreeService($projectRoot, $worktreesRoot, $boardService),
            new AgentReviewerSelector($boardService, $sessionService, $worktreesRoot),
            new AgentDeveloperSelector($boardService),
            $boardService,
            new FakeSessionDriver(),
            new FakeProcessSignaler(),
            new FakeProcessRunner(),
            new FakeBacklogCommandRunner(),
            $fakeRebase,
            null,
            $this->buildLaunchPromptResolver(),
        );

        $previousCwd = getcwd();
        $exitCode = null;
        try {
            chdir($projectRoot);
            ob_start();
            $exitCode = $cmd->handle(['claude'], ['developer' => true, 'code' => 'd22']);
            ob_end_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            echo "FAIL testDeveloperStageApprovedUpToDateSkipsAgent: unexpected exception: " . get_class($e) . ': ' . $e->getMessage() . "\n";
            return 1;
        } finally {
            if ($previousCwd !== false) {
                chdir($previousCwd);
            }
        }

        if ($exitCode !== 0) {
            echo "FAIL testDeveloperStageApprovedUpToDateSkipsAgent: expected exit code 0, got {$exitCode}\n";
            return 1;
        }
        if ($launcher->lastLaunchedWorktree !== null) {
            echo "FAIL testDeveloperStageApprovedUpToDateSkipsAgent: agent must not be launched when rebase is not needed\n";
            return 1;
        }
        if ($fakeRebase->lastCall === null) {
            echo "FAIL testDeveloperStageApprovedUpToDateSkipsAgent: rebase service was not called\n";
            return 1;
        }

        echo "OK testDeveloperStageApprovedUpToDateSkipsAgent\n";
        return 0;
    }

    private function testDeveloperStageApprovedConflictLaunchesAgentWithConflictPrompt(): int
    {
        // Developer with entry at stage=approved and a rebase conflict must launch the agent
        // with the conflict-resolution prompt.
        $projectRoot = $this->createGitProject('dev-approved-conflict');
        $worktreesRoot = $projectRoot . '/.agent-worktrees';
        $boardPath = BacklogPaths::boardPath($projectRoot);
        $worktree = $worktreesRoot . '/d23';

        $this->writeBoard($boardPath, [
            [
                'kind' => 'feature',
                'stage' => 'approved',
                'feature' => 'conflict-feature',
                'developer' => 'd23',
                'branch' => 'feat/conflict-feature',
                'type' => 'tech',
            ],
        ]);
        $this->runShell('git -C ' . escapeshellarg($projectRoot) . ' worktree add --detach ' . escapeshellarg($worktree) . ' HEAD');

        $launcher = new FakeAgentClientLauncher(AgentClient::CLAUDE);
        $registry = new AgentClientLauncherRegistry();
        $registry->register($launcher);

        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $sessionService = new AgentSessionService($projectRoot);
        $fakeRebase = new FakeEntryRebaseService(EntryRebaseResult::conflict('origin/main', ['src/Conflicted.php']));

        $cmd = new AgentStartCommand(
            $projectRoot,
            $worktreesRoot,
            $boardPath,
            $registry,
            new AgentCodeService($worktreesRoot, $boardPath, $boardService, $sessionService),
            $sessionService,
            new AgentContextBuilder($projectRoot, $boardPath, $boardService),
            $this->buildRealWorktreeService($projectRoot, $worktreesRoot, $boardService),
            new AgentReviewerSelector($boardService, $sessionService, $worktreesRoot),
            new AgentDeveloperSelector($boardService),
            $boardService,
            new FakeSessionDriver(),
            new FakeProcessSignaler(),
            new FakeProcessRunner(),
            new FakeBacklogCommandRunner(),
            $fakeRebase,
            null,
            $this->buildLaunchPromptResolver(),
        );

        $previousCwd = getcwd();
        try {
            chdir($projectRoot);
            $cmd->handle(['claude'], ['developer' => true, 'code' => 'd23']);
        } catch (\Throwable $e) {
            echo "FAIL testDeveloperStageApprovedConflictLaunchesAgentWithConflictPrompt: unexpected exception: "
                . get_class($e) . ': ' . $e->getMessage() . "\n";
            return 1;
        } finally {
            if ($previousCwd !== false) {
                chdir($previousCwd);
            }
        }

        if ($launcher->lastLaunchedWorktree === null) {
            echo "FAIL testDeveloperStageApprovedConflictLaunchesAgentWithConflictPrompt: agent must be launched on rebase conflict\n";
            return 1;
        }

        $expectedConflictPrompt = $this->buildLaunchPromptResolver()->resolveConflictPrompt();
        if ($launcher->lastInitialPrompt !== $expectedConflictPrompt) {
            echo "FAIL testDeveloperStageApprovedConflictLaunchesAgentWithConflictPrompt: expected conflict prompt, got "
                . var_export($launcher->lastInitialPrompt, true) . "\n";
            return 1;
        }

        echo "OK testDeveloperStageApprovedConflictLaunchesAgentWithConflictPrompt\n";
        return 0;
    }

    private function testReviewerStageApprovedRefusesViaResolver(): int
    {
        // The resolver must return a refusal for reviewer + stage=approved.
        // This validates the stage mapping independently of the full command flow.
        $resolver = $this->buildLaunchPromptResolver();
        $decision = $resolver->resolveStageDecision(AgentRole::REVIEWER, BacklogBoard::STAGE_APPROVED);

        if (!$decision->isRefusal()) {
            echo "FAIL testReviewerStageApprovedRefusesViaResolver: expected refusal for reviewer+approved, got prompt or launcher_handled\n";
            return 1;
        }

        if (!str_contains(mb_strtolower($decision->getMessage()), 'user-merge')) {
            echo "FAIL testReviewerStageApprovedRefusesViaResolver: refusal message does not mention user-merge. Got: "
                . $decision->getMessage() . "\n";
            return 1;
        }

        echo "OK testReviewerStageApprovedRefusesViaResolver\n";
        return 0;
    }

    /**
     * Builds an AgentStartCommand with reflection-built heavy collaborators.
     *
     * Sufficient for input-validation and "client unavailable" branches, which fail
     * before any worktree / context / session mutation is attempted.
     */
    private function buildCommand(AgentClientLauncher $launcher, ?AgentModelResolver $modelResolver = null): AgentStartCommand
    {
        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $sessionService = new AgentSessionService($this->tmpDir);
        $registry = new AgentClientLauncherRegistry();
        $registry->register($launcher);
        $codeService = new AgentCodeService(
            $this->tmpDir . '/worktrees',
            $this->tmpDir . '/board.yaml',
            $boardService,
            $sessionService,
        );
        $contextBuilder = new AgentContextBuilder($this->tmpDir, $this->tmpDir . '/board.yaml', $boardService);
        $worktreeService = (new \ReflectionClass(BacklogWorktreeService::class))->newInstanceWithoutConstructor();
        $reviewerSelector = new AgentReviewerSelector($boardService, $sessionService, $this->tmpDir . '/worktrees');

        return new AgentStartCommand(
            $this->tmpDir,
            $this->tmpDir . '/worktrees',
            $this->tmpDir . '/board.yaml',
            $registry,
            $codeService,
            $sessionService,
            $contextBuilder,
            $worktreeService,
            $reviewerSelector,
            new AgentDeveloperSelector($boardService),
            $boardService,
            new FakeSessionDriver(),
            new FakeProcessSignaler(),
            new FakeProcessRunner(),
            new FakeBacklogCommandRunner(),
            new NullEntryRebaseService(),
            $modelResolver,
            $this->buildLaunchPromptResolver(),
        );
    }

    private function buildProjectCommand(
        string $projectRoot,
        AgentClientLauncher $launcher,
        ?AgentModelResolver $modelResolver = null,
    ): AgentStartCommand {
        $boardPath = BacklogPaths::boardPath($projectRoot);
        $worktreesRoot = $projectRoot . '/.agent-worktrees';
        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $sessionService = new AgentSessionService($projectRoot);
        $registry = new AgentClientLauncherRegistry();
        $registry->register($launcher);

        return new AgentStartCommand(
            $projectRoot,
            $worktreesRoot,
            $boardPath,
            $registry,
            new AgentCodeService($worktreesRoot, $boardPath, $boardService, $sessionService),
            $sessionService,
            new AgentContextBuilder($projectRoot, $boardPath, $boardService),
            $this->buildRealWorktreeService($projectRoot, $worktreesRoot, $boardService),
            new AgentReviewerSelector($boardService, $sessionService, $worktreesRoot),
            new AgentDeveloperSelector($boardService),
            $boardService,
            new FakeSessionDriver(),
            new FakeProcessSignaler(),
            new FakeProcessRunner(),
            new FakeBacklogCommandRunner(),
            new NullEntryRebaseService(),
            $modelResolver,
            $this->buildLaunchPromptResolver(),
        );
    }

    private function buildModelResolver(): AgentModelResolver
    {
        return new AgentModelResolver(dirname(__DIR__, 4) . '/resources/backlog-agent/model-mapping.yaml');
    }

    private function buildLaunchPromptResolver(): AgentLaunchPromptResolver
    {
        return new AgentLaunchPromptResolver(dirname(__DIR__, 4) . '/resources/backlog-agent/launch-prompts.yaml');
    }

    /**
     * Writes a YAML backlog board.
     *
     * @param array<int, array<string, mixed>> $activeEntries Each entry: associative array with kind/stage/feature/etc.
     * @param array<int, array<string, mixed>> $todoEntries   Each entry: feature/task/type/title/agent
     */
    private function writeBoard(string $path, array $activeEntries, array $todoEntries = []): void
    {
        $data = [
            'version' => 1,
            'todo' => array_map([$this, 'normalizeTodoEntry'], $todoEntries),
            'active' => array_map([$this, 'normalizeActiveEntry'], $activeEntries),
        ];
        file_put_contents($path, Yaml::dump($data, 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE));
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function normalizeActiveEntry(array $entry): array
    {
        $order = ['kind', 'stage', 'feature', 'task', 'developer', 'reviewer', 'branch', 'feature-branch', 'base', 'pr', 'blocked', 'type'];
        $result = [];
        foreach ($order as $key) {
            if (array_key_exists($key, $entry)) {
                $result[$key] = $entry[$key];
            }
        }
        $result['title'] = $entry['title'] ?? ($entry['feature'] ?? '');
        if (array_key_exists('body', $entry)) {
            $result['body'] = $entry['body'];
        }
        foreach ($entry as $key => $value) {
            if (!array_key_exists($key, $result) && $key !== 'title' && $key !== 'body') {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function normalizeTodoEntry(array $entry): array
    {
        $result = ['feature' => $entry['feature']];
        if (array_key_exists('task', $entry)) {
            $result['task'] = $entry['task'];
        }
        if (array_key_exists('developer', $entry)) {
            $result['developer'] = $entry['developer'];
        }
        if (array_key_exists('type', $entry)) {
            $result['type'] = $entry['type'];
        }
        $result['title'] = $entry['title'] ?? $entry['feature'];
        if (array_key_exists('body', $entry)) {
            $result['body'] = $entry['body'];
        }

        return $result;
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

    private function testStartAttachesWhenSessionIsLive(): int
    {
        // When sessions.json has a live entry (driver isAlive=true + WA present),
        // start must call driver->resume() rather than driver->launch().
        $projectRoot = $this->createGitProject('attach-live');
        $worktreesRoot = $projectRoot . '/.agent-worktrees';
        $boardPath = BacklogPaths::boardPath($projectRoot);
        $worktree = $worktreesRoot . '/d30';

        $this->writeBoard($boardPath, [
            [
                'kind' => 'feature',
                'stage' => 'development',
                'feature' => 'live-feature',
                'developer' => 'd30',
                'branch' => 'feat/live-feature',
            ],
        ]);
        $this->runShell('git -C ' . escapeshellarg($projectRoot) . ' worktree add --detach ' . escapeshellarg($worktree) . ' HEAD');

        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $sessionService = new AgentSessionService($projectRoot);

        // Register an existing session entry that looks live.
        $this->writeSessionsJson($projectRoot, [
            'd30' => [
                'client' => 'claude',
                'role' => 'developer',
                'pid' => 55500,
                'worktree' => $worktree,
                'started_at' => '2026-01-01T00:00:00+00:00',
                'last_seen_at' => '2026-01-01T00:00:00+00:00',
                'session_id' => null,
            ],
        ]);

        $launcher = new FakeAgentClientLauncher(AgentClient::CLAUDE);
        $registry = new AgentClientLauncherRegistry();
        $registry->register($launcher);

        $driver = new FakeSessionDriver();
        // Driver reports the session as alive (tmux session alive, wrapper PID dead → re-attach allowed).
        $driver->setAlive('d30', true);
        $driver->setAllowsResumeWhileAlive(true);

        $signaler = new FakeProcessSignaler();
        // Wrapper PID 55500 is dead → guard allows re-attach.

        $cmd = new AgentStartCommand(
            $projectRoot,
            $worktreesRoot,
            $boardPath,
            $registry,
            new AgentCodeService($worktreesRoot, $boardPath, $boardService, $sessionService),
            $sessionService,
            new AgentContextBuilder($projectRoot, $boardPath, $boardService),
            $this->buildRealWorktreeService($projectRoot, $worktreesRoot, $boardService),
            new AgentReviewerSelector($boardService, $sessionService, $worktreesRoot),
            new AgentDeveloperSelector($boardService),
            $boardService,
            $driver,
            $signaler,
            new FakeProcessRunner(),
            new FakeBacklogCommandRunner(),
            new NullEntryRebaseService(),
        );

        $previousCwd = getcwd();
        try {
            chdir($projectRoot);
            $cmd->handle(['claude'], ['developer' => true, 'code' => 'd30']);
        } catch (\Throwable $e) {
            echo "FAIL testStartAttachesWhenSessionIsLive: unexpected " . get_class($e) . ': ' . $e->getMessage() . "\n";
            return 1;
        } finally {
            if ($previousCwd !== false) {
                chdir($previousCwd);
            }
        }

        if ($driver->lastResumeCall === null) {
            echo "FAIL testStartAttachesWhenSessionIsLive: expected driver->resume() to be called, got launch()\n";
            return 1;
        }

        if ($driver->lastLaunchCall !== null) {
            echo "FAIL testStartAttachesWhenSessionIsLive: driver->launch() must not be called on re-attach\n";
            return 1;
        }

        echo "OK testStartAttachesWhenSessionIsLive\n";
        return 0;
    }

    private function testStartCleansGhostSessionWhenDriverDead(): int
    {
        // When sessions.json has an entry but driver.isAlive()=false (ghost), start must
        // print a cleanup message, remove the entry, and create a fresh session.
        $projectRoot = $this->createGitProject('ghost-driver-dead');
        $worktreesRoot = $projectRoot . '/.agent-worktrees';
        $boardPath = BacklogPaths::boardPath($projectRoot);
        $worktree = $worktreesRoot . '/d31';

        $this->writeBoard($boardPath, [
            [
                'kind' => 'feature',
                'stage' => 'development',
                'feature' => 'ghost-feature',
                'developer' => 'd31',
                'branch' => 'feat/ghost-feature',
            ],
        ]);
        $this->runShell('git -C ' . escapeshellarg($projectRoot) . ' worktree add --detach ' . escapeshellarg($worktree) . ' HEAD');

        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $sessionService = new AgentSessionService($projectRoot);

        $this->writeSessionsJson($projectRoot, [
            'd31' => [
                'client' => 'claude',
                'role' => 'developer',
                'pid' => 99901,
                'worktree' => $worktree,
                'started_at' => '2026-01-01T00:00:00+00:00',
                'last_seen_at' => '2026-01-01T00:00:00+00:00',
                'session_id' => null,
            ],
        ]);

        $launcher = new FakeAgentClientLauncher(AgentClient::CLAUDE);
        $registry = new AgentClientLauncherRegistry();
        $registry->register($launcher);

        $driver = new FakeSessionDriver();
        // isAlive=false (default) → ghost session

        $cmd = new AgentStartCommand(
            $projectRoot,
            $worktreesRoot,
            $boardPath,
            $registry,
            new AgentCodeService($worktreesRoot, $boardPath, $boardService, $sessionService),
            $sessionService,
            new AgentContextBuilder($projectRoot, $boardPath, $boardService),
            $this->buildRealWorktreeService($projectRoot, $worktreesRoot, $boardService),
            new AgentReviewerSelector($boardService, $sessionService, $worktreesRoot),
            new AgentDeveloperSelector($boardService),
            $boardService,
            $driver,
            new FakeProcessSignaler(),
            new FakeProcessRunner(),
            new FakeBacklogCommandRunner(),
            new NullEntryRebaseService(),
        );

        $previousCwd = getcwd();
        $output = '';
        try {
            chdir($projectRoot);
            ob_start();
            $cmd->handle(['claude'], ['developer' => true, 'code' => 'd31']);
            $output = (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            echo "FAIL testStartCleansGhostSessionWhenDriverDead: unexpected " . get_class($e) . ': ' . $e->getMessage() . "\n";
            return 1;
        } finally {
            if ($previousCwd !== false) {
                chdir($previousCwd);
            }
        }

        if (!str_contains($output, 'Stale session for d31')) {
            echo "FAIL testStartCleansGhostSessionWhenDriverDead: expected cleanup message, got: {$output}\n";
            return 1;
        }

        if ($driver->lastLaunchCall === null) {
            echo "FAIL testStartCleansGhostSessionWhenDriverDead: expected driver->launch() after cleanup\n";
            return 1;
        }

        if ($driver->lastResumeCall !== null) {
            echo "FAIL testStartCleansGhostSessionWhenDriverDead: driver->resume() must not be called for ghost\n";
            return 1;
        }

        echo "OK testStartCleansGhostSessionWhenDriverDead\n";
        return 0;
    }

    private function testStartCleansGhostSessionWhenWorktreeAbsent(): int
    {
        // When sessions.json has an entry with driver.isAlive()=true but the stored worktree
        // directory is missing, start must treat it as a ghost: cleanup + create a new session.
        $projectRoot = $this->createGitProject('ghost-wa-absent');
        $worktreesRoot = $projectRoot . '/.agent-worktrees';
        $boardPath = BacklogPaths::boardPath($projectRoot);
        $missingWorktree = $worktreesRoot . '/d32';

        $this->writeBoard($boardPath, [
            [
                'kind' => 'feature',
                'stage' => 'development',
                'feature' => 'wa-absent-feature',
                'developer' => 'd32',
                'branch' => 'feat/wa-absent-feature',
            ],
        ]);
        // Note: we do NOT create the worktree directory — it is intentionally absent.

        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $sessionService = new AgentSessionService($projectRoot);

        $this->writeSessionsJson($projectRoot, [
            'd32' => [
                'client' => 'claude',
                'role' => 'developer',
                'pid' => 88802,
                'worktree' => $missingWorktree,
                'started_at' => '2026-01-01T00:00:00+00:00',
                'last_seen_at' => '2026-01-01T00:00:00+00:00',
                'session_id' => null,
            ],
        ]);

        $launcher = new FakeAgentClientLauncher(AgentClient::CLAUDE);
        $registry = new AgentClientLauncherRegistry();
        $registry->register($launcher);

        $driver = new FakeSessionDriver();
        // Driver reports alive, but worktree does not exist → ghost (isLive = false)
        $driver->setAlive('d32', true);

        $cmd = new AgentStartCommand(
            $projectRoot,
            $worktreesRoot,
            $boardPath,
            $registry,
            new AgentCodeService($worktreesRoot, $boardPath, $boardService, $sessionService),
            $sessionService,
            new AgentContextBuilder($projectRoot, $boardPath, $boardService),
            $this->buildRealWorktreeService($projectRoot, $worktreesRoot, $boardService),
            new AgentReviewerSelector($boardService, $sessionService, $worktreesRoot),
            new AgentDeveloperSelector($boardService),
            $boardService,
            $driver,
            new FakeProcessSignaler(),
            new FakeProcessRunner(),
            new FakeBacklogCommandRunner(),
            new NullEntryRebaseService(),
        );

        $previousCwd = getcwd();
        $output = '';
        try {
            chdir($projectRoot);
            ob_start();
            $cmd->handle(['claude'], ['developer' => true, 'code' => 'd32']);
            $output = (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            echo "FAIL testStartCleansGhostSessionWhenWorktreeAbsent: unexpected " . get_class($e) . ': ' . $e->getMessage() . "\n";
            return 1;
        } finally {
            if ($previousCwd !== false) {
                chdir($previousCwd);
            }
        }

        if (!str_contains($output, 'Stale session for d32')) {
            echo "FAIL testStartCleansGhostSessionWhenWorktreeAbsent: expected cleanup message, got: {$output}\n";
            return 1;
        }

        if ($driver->lastLaunchCall === null) {
            echo "FAIL testStartCleansGhostSessionWhenWorktreeAbsent: expected driver->launch() after cleanup\n";
            return 1;
        }

        echo "OK testStartCleansGhostSessionWhenWorktreeAbsent\n";
        return 0;
    }

    private function testForceNewDropsLiveSessionAndCreatesNew(): int
    {
        // When --force-new is passed and there is a live session, start must drop the live
        // session (kill driver session + remove sessions.json entry) and create a new one.
        $projectRoot = $this->createGitProject('force-new-live');
        $worktreesRoot = $projectRoot . '/.agent-worktrees';
        $boardPath = BacklogPaths::boardPath($projectRoot);
        $worktree = $worktreesRoot . '/d33';

        $this->writeBoard($boardPath, [
            [
                'kind' => 'feature',
                'stage' => 'development',
                'feature' => 'force-feature',
                'developer' => 'd33',
                'branch' => 'feat/force-feature',
            ],
        ]);
        $this->runShell('git -C ' . escapeshellarg($projectRoot) . ' worktree add --detach ' . escapeshellarg($worktree) . ' HEAD');

        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $sessionService = new AgentSessionService($projectRoot);

        $this->writeSessionsJson($projectRoot, [
            'd33' => [
                'client' => 'claude',
                'role' => 'developer',
                'pid' => 77703,
                'worktree' => $worktree,
                'started_at' => '2026-01-01T00:00:00+00:00',
                'last_seen_at' => '2026-01-01T00:00:00+00:00',
                'session_id' => null,
            ],
        ]);

        $launcher = new FakeAgentClientLauncher(AgentClient::CLAUDE);
        $registry = new AgentClientLauncherRegistry();
        $registry->register($launcher);

        $driver = new FakeSessionDriver();
        $driver->setAlive('d33', true);
        $driver->setExists('d33', true);

        $cmd = new AgentStartCommand(
            $projectRoot,
            $worktreesRoot,
            $boardPath,
            $registry,
            new AgentCodeService($worktreesRoot, $boardPath, $boardService, $sessionService),
            $sessionService,
            new AgentContextBuilder($projectRoot, $boardPath, $boardService),
            $this->buildRealWorktreeService($projectRoot, $worktreesRoot, $boardService),
            new AgentReviewerSelector($boardService, $sessionService, $worktreesRoot),
            new AgentDeveloperSelector($boardService),
            $boardService,
            $driver,
            new FakeProcessSignaler(),
            new FakeProcessRunner(),
            new FakeBacklogCommandRunner(),
            new NullEntryRebaseService(),
        );

        $previousCwd = getcwd();
        $output = '';
        try {
            chdir($projectRoot);
            ob_start();
            $cmd->handle(['claude'], ['developer' => true, 'code' => 'd33', BacklogCliOption::FORCE_NEW->value => true]);
            $output = (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            echo "FAIL testForceNewDropsLiveSessionAndCreatesNew: unexpected " . get_class($e) . ': ' . $e->getMessage() . "\n";
            return 1;
        } finally {
            if ($previousCwd !== false) {
                chdir($previousCwd);
            }
        }

        if (!str_contains($output, 'Dropping live session for d33')) {
            echo "FAIL testForceNewDropsLiveSessionAndCreatesNew: expected drop message, got: {$output}\n";
            return 1;
        }

        if (!in_array('d33', $driver->killedCodes, true)) {
            echo "FAIL testForceNewDropsLiveSessionAndCreatesNew: expected driver->kill('d33') to be called\n";
            return 1;
        }

        if ($driver->lastLaunchCall === null) {
            echo "FAIL testForceNewDropsLiveSessionAndCreatesNew: expected driver->launch() after drop\n";
            return 1;
        }

        if ($driver->lastResumeCall !== null) {
            echo "FAIL testForceNewDropsLiveSessionAndCreatesNew: driver->resume() must not be called after force-new\n";
            return 1;
        }

        echo "OK testForceNewDropsLiveSessionAndCreatesNew\n";
        return 0;
    }

    private function testWaOccupantAcceptAdoptsExistingSession(): int
    {
        // When the operator chooses Accept for an occupied developer WA, the entry must be
        // assigned to the existing reviewer session (r99) via review-next, and that session
        // must be re-attached via driver->resume() rather than a fresh launch.
        $dir = $this->scratchDir('wa-accept');
        $boardPath = $dir . '/board.yaml';
        $worktreesRoot = $dir . '/worktrees';
        $devWa = $worktreesRoot . '/d04';
        mkdir($devWa, 0755, true);

        $this->writeBoard($boardPath, [
            [
                'kind' => 'feature',
                'stage' => 'review',
                'feature' => self::FEATURE_CRYPTO,
                'developer' => 'd04',
                'branch' => 'feat/' . self::FEATURE_CRYPTO,
                'type' => 'feat',
            ],
        ]);

        // Existing reviewer r99 occupies d04's WA.
        $this->writeSessionsJson($dir, [
            'r99' => [
                'client' => 'claude',
                'role' => 'reviewer',
                'pid' => 99999,
                'worktree' => $devWa,
                'started_at' => '2026-01-01T00:00:00+00:00',
                'last_seen_at' => '2026-01-01T00:00:00+00:00',
                'session_id' => null,
            ],
        ]);

        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $sessionService = new AgentSessionService($dir);
        $reviewerSelector = new AgentReviewerSelector($boardService, $sessionService, $worktreesRoot);
        $launcher = new FakeAgentClientLauncher(AgentClient::CLAUDE);
        $registry = new AgentClientLauncherRegistry();
        $registry->register($launcher);

        $fakeRunner = new FakeBacklogCommandRunner();
        $fakeRunner->onReviewNext = static function (string $reviewerCode, string $entryRef) use ($boardService, $boardPath): void {
            $board = $boardService->loadBoard($boardPath);
            foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $entry) {
                if ($entry->getFeature() === $entryRef) {
                    $entry->setStage(BacklogBoard::STAGE_REVIEWING);
                    $entry->setReviewer($reviewerCode);
                    $boardService->saveBoard($board);
                    break;
                }
            }
        };

        $driver = new FakeSessionDriver();
        // r99 is alive and detached → eligible for Accept prompt.
        $driver->setAlive('r99', true);
        $driver->setAttached('r99', false);
        // Allow resume while alive (simulates tmux re-attach behaviour).
        $driver->setAllowsResumeWhileAlive(true);

        $cmd = new AgentStartCommand(
            $dir,
            $worktreesRoot,
            $boardPath,
            $registry,
            new AgentCodeService($worktreesRoot, $boardPath, $boardService, $sessionService),
            $sessionService,
            new AgentContextBuilder($dir, $boardPath, $boardService),
            (new \ReflectionClass(BacklogWorktreeService::class))->newInstanceWithoutConstructor(),
            $reviewerSelector,
            new AgentDeveloperSelector($boardService),
            $boardService,
            $driver,
            new FakeProcessSignaler(),
            new FakeProcessRunner(),
            $fakeRunner,
            new NullEntryRebaseService(),
            null,
            $this->buildLaunchPromptResolver(),
            static fn(AgentSession $existing, BoardEntry $entry, bool $hasMore): WaOccupantChoice => WaOccupantChoice::Accept,
        );

        $previousCwd = getcwd();
        try {
            $cmd->handle(['claude'], ['reviewer' => true, 'code' => 'r01']);
        } catch (\Throwable $e) {
            echo "FAIL testWaOccupantAcceptAdoptsExistingSession: unexpected " . get_class($e) . ': ' . $e->getMessage() . "\n";
            return 1;
        } finally {
            if ($previousCwd !== false) {
                chdir($previousCwd);
            }
        }

        // review-next must be called with the existing reviewer code (r99), not the new one (r01).
        if (count($fakeRunner->calls) !== 1 || $fakeRunner->calls[0]['method'] !== 'reviewNext') {
            echo "FAIL testWaOccupantAcceptAdoptsExistingSession: expected one reviewNext call, got " . json_encode($fakeRunner->calls) . "\n";
            return 1;
        }
        if ($fakeRunner->calls[0]['reviewerCode'] !== 'r99' || $fakeRunner->calls[0]['entryRef'] !== self::FEATURE_CRYPTO) {
            echo "FAIL testWaOccupantAcceptAdoptsExistingSession: unexpected reviewNext args: " . json_encode($fakeRunner->calls[0]) . "\n";
            return 1;
        }

        // The existing session (r99) must be re-attached via resume(), not launch().
        if ($driver->lastResumeCall === null || $driver->lastResumeCall['agentCode'] !== 'r99') {
            $code = $driver->lastResumeCall['agentCode'] ?? '<null>';
            echo "FAIL testWaOccupantAcceptAdoptsExistingSession: expected driver->resume('r99'), got '{$code}'\n";
            return 1;
        }
        if ($driver->lastLaunchCall !== null) {
            echo "FAIL testWaOccupantAcceptAdoptsExistingSession: driver->launch() must not be called on adopt\n";
            return 1;
        }

        echo "OK testWaOccupantAcceptAdoptsExistingSession\n";
        return 0;
    }

    private function testWaOccupantPassSkipsToNextEntry(): int
    {
        // When the operator chooses Pass for the first entry (occupied WA), the picker must skip
        // it and claim the second review-stage entry (free WA) via review-next.
        $dir = $this->scratchDir('wa-pass');
        $boardPath = $dir . '/board.yaml';
        $worktreesRoot = $dir . '/worktrees';
        $devWaD04 = $worktreesRoot . '/d04';
        $devWaD05 = $worktreesRoot . '/d05';
        mkdir($devWaD04, 0755, true);
        mkdir($devWaD05, 0755, true);

        $this->writeBoard($boardPath, [
            [
                'kind' => 'feature',
                'stage' => 'review',
                'feature' => self::FEATURE_CRYPTO,
                'developer' => 'd04',
                'branch' => 'feat/' . self::FEATURE_CRYPTO,
                'type' => 'feat',
            ],
            [
                'kind' => 'feature',
                'stage' => 'review',
                'feature' => self::FEATURE_MY,
                'developer' => 'd05',
                'branch' => 'feat/' . self::FEATURE_MY,
                'type' => 'feat',
            ],
        ]);

        // r99 occupies d04's WA.
        $this->writeSessionsJson($dir, [
            'r99' => [
                'client' => 'claude',
                'role' => 'reviewer',
                'pid' => 99999,
                'worktree' => $devWaD04,
                'started_at' => '2026-01-01T00:00:00+00:00',
                'last_seen_at' => '2026-01-01T00:00:00+00:00',
                'session_id' => null,
            ],
        ]);

        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $sessionService = new AgentSessionService($dir);
        $reviewerSelector = new AgentReviewerSelector($boardService, $sessionService, $worktreesRoot);
        $launcher = new FakeAgentClientLauncher(AgentClient::CLAUDE);
        $registry = new AgentClientLauncherRegistry();
        $registry->register($launcher);

        $fakeRunner = new FakeBacklogCommandRunner();
        $fakeRunner->onReviewNext = static function (string $reviewerCode, string $entryRef) use ($boardService, $boardPath): void {
            $board = $boardService->loadBoard($boardPath);
            foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $entry) {
                if ($entry->getFeature() === $entryRef) {
                    $entry->setStage(BacklogBoard::STAGE_REVIEWING);
                    $entry->setReviewer($reviewerCode);
                    $boardService->saveBoard($board);
                    break;
                }
            }
        };

        $driver = new FakeSessionDriver();
        $driver->setAlive('r99', true);
        $driver->setAttached('r99', false);

        $cmd = new AgentStartCommand(
            $dir,
            $worktreesRoot,
            $boardPath,
            $registry,
            new AgentCodeService($worktreesRoot, $boardPath, $boardService, $sessionService),
            $sessionService,
            new AgentContextBuilder($dir, $boardPath, $boardService),
            (new \ReflectionClass(BacklogWorktreeService::class))->newInstanceWithoutConstructor(),
            $reviewerSelector,
            new AgentDeveloperSelector($boardService),
            $boardService,
            $driver,
            new FakeProcessSignaler(),
            new FakeProcessRunner(),
            $fakeRunner,
            new NullEntryRebaseService(),
            null,
            $this->buildLaunchPromptResolver(),
            static fn(AgentSession $existing, BoardEntry $entry, bool $hasMore): WaOccupantChoice => WaOccupantChoice::Pass,
        );

        $previousCwd = getcwd();
        try {
            $cmd->handle(['claude'], ['reviewer' => true, 'code' => 'r01']);
        } catch (\Throwable $e) {
            echo "FAIL testWaOccupantPassSkipsToNextEntry: unexpected " . get_class($e) . ': ' . $e->getMessage() . "\n";
            return 1;
        } finally {
            if ($previousCwd !== false) {
                chdir($previousCwd);
            }
        }

        // review-next must be called once with r01 for the second entry (my-feature), not the first.
        if (count($fakeRunner->calls) !== 1 || $fakeRunner->calls[0]['method'] !== 'reviewNext') {
            echo "FAIL testWaOccupantPassSkipsToNextEntry: expected one reviewNext call, got " . json_encode($fakeRunner->calls) . "\n";
            return 1;
        }
        if ($fakeRunner->calls[0]['reviewerCode'] !== 'r01' || $fakeRunner->calls[0]['entryRef'] !== self::FEATURE_MY) {
            echo "FAIL testWaOccupantPassSkipsToNextEntry: unexpected reviewNext args: " . json_encode($fakeRunner->calls[0]) . "\n";
            return 1;
        }

        // The second entry's WA must be the launch target.
        if ($driver->lastLaunchCall === null || $driver->lastLaunchCall['cwd'] !== $devWaD05) {
            $cwd = $driver->lastLaunchCall['cwd'] ?? '<null>';
            echo "FAIL testWaOccupantPassSkipsToNextEntry: expected launch on '{$devWaD05}', got '{$cwd}'\n";
            return 1;
        }

        echo "OK testWaOccupantPassSkipsToNextEntry\n";
        return 0;
    }

    private function testWaOccupantQuitAbortsPickerReturnsZero(): int
    {
        // When the operator chooses Quit, the picker must abort immediately, claim no entry,
        // and handle() must return 0 without launching or resuming any session.
        $dir = $this->scratchDir('wa-quit');
        $boardPath = $dir . '/board.yaml';
        $worktreesRoot = $dir . '/worktrees';
        $devWa = $worktreesRoot . '/d04';
        mkdir($devWa, 0755, true);

        $this->writeBoard($boardPath, [
            [
                'kind' => 'feature',
                'stage' => 'review',
                'feature' => self::FEATURE_CRYPTO,
                'developer' => 'd04',
                'branch' => 'feat/' . self::FEATURE_CRYPTO,
                'type' => 'feat',
            ],
        ]);

        $this->writeSessionsJson($dir, [
            'r99' => [
                'client' => 'claude',
                'role' => 'reviewer',
                'pid' => 99999,
                'worktree' => $devWa,
                'started_at' => '2026-01-01T00:00:00+00:00',
                'last_seen_at' => '2026-01-01T00:00:00+00:00',
                'session_id' => null,
            ],
        ]);

        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $sessionService = new AgentSessionService($dir);
        $reviewerSelector = new AgentReviewerSelector($boardService, $sessionService, $worktreesRoot);
        $launcher = new FakeAgentClientLauncher(AgentClient::CLAUDE);
        $registry = new AgentClientLauncherRegistry();
        $registry->register($launcher);

        $fakeRunner = new FakeBacklogCommandRunner();
        $driver = new FakeSessionDriver();
        $driver->setAlive('r99', true);
        $driver->setAttached('r99', false);

        $cmd = new AgentStartCommand(
            $dir,
            $worktreesRoot,
            $boardPath,
            $registry,
            new AgentCodeService($worktreesRoot, $boardPath, $boardService, $sessionService),
            $sessionService,
            new AgentContextBuilder($dir, $boardPath, $boardService),
            (new \ReflectionClass(BacklogWorktreeService::class))->newInstanceWithoutConstructor(),
            $reviewerSelector,
            new AgentDeveloperSelector($boardService),
            $boardService,
            $driver,
            new FakeProcessSignaler(),
            new FakeProcessRunner(),
            $fakeRunner,
            new NullEntryRebaseService(),
            null,
            $this->buildLaunchPromptResolver(),
            static fn(AgentSession $existing, BoardEntry $entry, bool $hasMore): WaOccupantChoice => WaOccupantChoice::Quit,
        );

        $previousCwd = getcwd();
        $exitCode = null;
        try {
            $exitCode = $cmd->handle(['claude'], ['reviewer' => true, 'code' => 'r01']);
        } catch (\Throwable $e) {
            echo "FAIL testWaOccupantQuitAbortsPickerReturnsZero: unexpected " . get_class($e) . ': ' . $e->getMessage() . "\n";
            return 1;
        } finally {
            if ($previousCwd !== false) {
                chdir($previousCwd);
            }
        }

        if ($exitCode !== 0) {
            echo "FAIL testWaOccupantQuitAbortsPickerReturnsZero: expected exit code 0, got {$exitCode}\n";
            return 1;
        }
        if (!empty($fakeRunner->calls)) {
            echo "FAIL testWaOccupantQuitAbortsPickerReturnsZero: review-next must not be called on Quit, got " . json_encode($fakeRunner->calls) . "\n";
            return 1;
        }
        if ($driver->lastLaunchCall !== null || $driver->lastResumeCall !== null) {
            echo "FAIL testWaOccupantQuitAbortsPickerReturnsZero: no driver call expected on Quit\n";
            return 1;
        }

        echo "OK testWaOccupantQuitAbortsPickerReturnsZero\n";
        return 0;
    }

    private function testDeadRegistrySessionIsCleanedAndEntryTakenNormally(): int
    {
        // When the registry has a reviewer session for the target WA but the driver reports
        // that session as dead, the command must silently remove the registry entry and then
        // claim the entry normally as a fresh reviewer session (r01).
        $dir = $this->scratchDir('wa-dead');
        $boardPath = $dir . '/board.yaml';
        $worktreesRoot = $dir . '/worktrees';
        $devWa = $worktreesRoot . '/d04';
        mkdir($devWa, 0755, true);

        $this->writeBoard($boardPath, [
            [
                'kind' => 'feature',
                'stage' => 'review',
                'feature' => self::FEATURE_CRYPTO,
                'developer' => 'd04',
                'branch' => 'feat/' . self::FEATURE_CRYPTO,
                'type' => 'feat',
            ],
        ]);

        // r99 is in the registry but its driver process is dead (isAlive defaults to false).
        $this->writeSessionsJson($dir, [
            'r99' => [
                'client' => 'claude',
                'role' => 'reviewer',
                'pid' => 99999,
                'worktree' => $devWa,
                'started_at' => '2026-01-01T00:00:00+00:00',
                'last_seen_at' => '2026-01-01T00:00:00+00:00',
                'session_id' => null,
            ],
        ]);

        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $sessionService = new AgentSessionService($dir);
        $reviewerSelector = new AgentReviewerSelector($boardService, $sessionService, $worktreesRoot);
        $launcher = new FakeAgentClientLauncher(AgentClient::CLAUDE);
        $registry = new AgentClientLauncherRegistry();
        $registry->register($launcher);

        $fakeRunner = new FakeBacklogCommandRunner();
        $fakeRunner->onReviewNext = static function (string $reviewerCode, string $entryRef) use ($boardService, $boardPath): void {
            $board = $boardService->loadBoard($boardPath);
            foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $entry) {
                if ($entry->getFeature() === $entryRef) {
                    $entry->setStage(BacklogBoard::STAGE_REVIEWING);
                    $entry->setReviewer($reviewerCode);
                    $boardService->saveBoard($board);
                    break;
                }
            }
        };

        // Driver reports r99 as dead by default (isAlive not set).
        $driver = new FakeSessionDriver();

        $cmd = new AgentStartCommand(
            $dir,
            $worktreesRoot,
            $boardPath,
            $registry,
            new AgentCodeService($worktreesRoot, $boardPath, $boardService, $sessionService),
            $sessionService,
            new AgentContextBuilder($dir, $boardPath, $boardService),
            (new \ReflectionClass(BacklogWorktreeService::class))->newInstanceWithoutConstructor(),
            $reviewerSelector,
            new AgentDeveloperSelector($boardService),
            $boardService,
            $driver,
            new FakeProcessSignaler(),
            new FakeProcessRunner(),
            $fakeRunner,
            new NullEntryRebaseService(),
            null,
            $this->buildLaunchPromptResolver(),
        );

        $previousCwd = getcwd();
        try {
            $cmd->handle(['claude'], ['reviewer' => true, 'code' => 'r01']);
        } catch (\Throwable $e) {
            echo "FAIL testDeadRegistrySessionIsCleanedAndEntryTakenNormally: unexpected " . get_class($e) . ': ' . $e->getMessage() . "\n";
            return 1;
        } finally {
            if ($previousCwd !== false) {
                chdir($previousCwd);
            }
        }

        // r99 must have been removed from the registry.
        if ($sessionService->get('r99') !== null) {
            echo "FAIL testDeadRegistrySessionIsCleanedAndEntryTakenNormally: dead session r99 must be removed from registry\n";
            return 1;
        }

        // review-next must be called with r01 for the entry.
        if (count($fakeRunner->calls) !== 1 || $fakeRunner->calls[0]['method'] !== 'reviewNext') {
            echo "FAIL testDeadRegistrySessionIsCleanedAndEntryTakenNormally: expected one reviewNext call, got " . json_encode($fakeRunner->calls) . "\n";
            return 1;
        }
        if ($fakeRunner->calls[0]['reviewerCode'] !== 'r01' || $fakeRunner->calls[0]['entryRef'] !== self::FEATURE_CRYPTO) {
            echo "FAIL testDeadRegistrySessionIsCleanedAndEntryTakenNormally: unexpected reviewNext args: " . json_encode($fakeRunner->calls[0]) . "\n";
            return 1;
        }

        // The entry must be claimed (launch called on devWa).
        if ($driver->lastLaunchCall === null || $driver->lastLaunchCall['cwd'] !== $devWa) {
            $cwd = $driver->lastLaunchCall['cwd'] ?? '<null>';
            echo "FAIL testDeadRegistrySessionIsCleanedAndEntryTakenNormally: expected launch on '{$devWa}', got '{$cwd}'\n";
            return 1;
        }

        echo "OK testDeadRegistrySessionIsCleanedAndEntryTakenNormally\n";
        return 0;
    }

    private function testAliveAttachedSessionIsAutoPassedToNextEntry(): int
    {
        // When the existing reviewer session is alive and attached to another terminal, the picker
        // must auto-pass the first entry (no prompt) and claim the second review-stage entry.
        $dir = $this->scratchDir('wa-attached');
        $boardPath = $dir . '/board.yaml';
        $worktreesRoot = $dir . '/worktrees';
        $devWaD04 = $worktreesRoot . '/d04';
        $devWaD05 = $worktreesRoot . '/d05';
        mkdir($devWaD04, 0755, true);
        mkdir($devWaD05, 0755, true);

        $this->writeBoard($boardPath, [
            [
                'kind' => 'feature',
                'stage' => 'review',
                'feature' => self::FEATURE_CRYPTO,
                'developer' => 'd04',
                'branch' => 'feat/' . self::FEATURE_CRYPTO,
                'type' => 'feat',
            ],
            [
                'kind' => 'feature',
                'stage' => 'review',
                'feature' => self::FEATURE_MY,
                'developer' => 'd05',
                'branch' => 'feat/' . self::FEATURE_MY,
                'type' => 'feat',
            ],
        ]);

        // r99 is attached to another terminal in d04's WA.
        $this->writeSessionsJson($dir, [
            'r99' => [
                'client' => 'claude',
                'role' => 'reviewer',
                'pid' => 99999,
                'worktree' => $devWaD04,
                'started_at' => '2026-01-01T00:00:00+00:00',
                'last_seen_at' => '2026-01-01T00:00:00+00:00',
                'session_id' => null,
            ],
        ]);

        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $sessionService = new AgentSessionService($dir);
        $reviewerSelector = new AgentReviewerSelector($boardService, $sessionService, $worktreesRoot);
        $launcher = new FakeAgentClientLauncher(AgentClient::CLAUDE);
        $registry = new AgentClientLauncherRegistry();
        $registry->register($launcher);

        $fakeRunner = new FakeBacklogCommandRunner();
        $fakeRunner->onReviewNext = static function (string $reviewerCode, string $entryRef) use ($boardService, $boardPath): void {
            $board = $boardService->loadBoard($boardPath);
            foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $entry) {
                if ($entry->getFeature() === $entryRef) {
                    $entry->setStage(BacklogBoard::STAGE_REVIEWING);
                    $entry->setReviewer($reviewerCode);
                    $boardService->saveBoard($board);
                    break;
                }
            }
        };

        $driver = new FakeSessionDriver();
        $driver->setAlive('r99', true);
        $driver->setAttached('r99', true); // Already attached elsewhere: auto-pass.

        $cmd = new AgentStartCommand(
            $dir,
            $worktreesRoot,
            $boardPath,
            $registry,
            new AgentCodeService($worktreesRoot, $boardPath, $boardService, $sessionService),
            $sessionService,
            new AgentContextBuilder($dir, $boardPath, $boardService),
            (new \ReflectionClass(BacklogWorktreeService::class))->newInstanceWithoutConstructor(),
            $reviewerSelector,
            new AgentDeveloperSelector($boardService),
            $boardService,
            $driver,
            new FakeProcessSignaler(),
            new FakeProcessRunner(),
            $fakeRunner,
            new NullEntryRebaseService(),
            null,
            $this->buildLaunchPromptResolver(),
        );

        $previousCwd = getcwd();
        $output = '';
        try {
            ob_start();
            $cmd->handle(['claude'], ['reviewer' => true, 'code' => 'r01']);
            $output = (string) ob_get_clean();
        } catch (\Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            echo "FAIL testAliveAttachedSessionIsAutoPassedToNextEntry: unexpected " . get_class($e) . ': ' . $e->getMessage() . "\n";
            return 1;
        } finally {
            if ($previousCwd !== false) {
                chdir($previousCwd);
            }
        }

        // Auto-pass message must mention that r99 is already attached.
        if (!str_contains($output, 'r99') || !str_contains($output, 'already attached')) {
            echo "FAIL testAliveAttachedSessionIsAutoPassedToNextEntry: expected auto-pass message for r99, got: {$output}\n";
            return 1;
        }

        // The second entry must be claimed (r01 takes my-feature).
        if (count($fakeRunner->calls) !== 1 || $fakeRunner->calls[0]['entryRef'] !== self::FEATURE_MY) {
            echo "FAIL testAliveAttachedSessionIsAutoPassedToNextEntry: expected reviewNext for my-feature, got " . json_encode($fakeRunner->calls) . "\n";
            return 1;
        }

        if ($driver->lastLaunchCall === null || $driver->lastLaunchCall['cwd'] !== $devWaD05) {
            $cwd = $driver->lastLaunchCall['cwd'] ?? '<null>';
            echo "FAIL testAliveAttachedSessionIsAutoPassedToNextEntry: expected launch on '{$devWaD05}', got '{$cwd}'\n";
            return 1;
        }

        echo "OK testAliveAttachedSessionIsAutoPassedToNextEntry\n";
        return 0;
    }

    private function testPassOptionNotOfferedForSingleCandidate(): int
    {
        // When there is only one candidate entry, the conflict prompter must receive
        // hasMoreCandidates=false so the Pass option is not offered to the operator.
        $dir = $this->scratchDir('wa-no-pass');
        $boardPath = $dir . '/board.yaml';
        $worktreesRoot = $dir . '/worktrees';
        $devWa = $worktreesRoot . '/d04';
        mkdir($devWa, 0755, true);

        $this->writeBoard($boardPath, [
            [
                'kind' => 'feature',
                'stage' => 'review',
                'feature' => self::FEATURE_CRYPTO,
                'developer' => 'd04',
                'branch' => 'feat/' . self::FEATURE_CRYPTO,
                'type' => 'feat',
            ],
        ]);

        $this->writeSessionsJson($dir, [
            'r99' => [
                'client' => 'claude',
                'role' => 'reviewer',
                'pid' => 99999,
                'worktree' => $devWa,
                'started_at' => '2026-01-01T00:00:00+00:00',
                'last_seen_at' => '2026-01-01T00:00:00+00:00',
                'session_id' => null,
            ],
        ]);

        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $sessionService = new AgentSessionService($dir);
        $reviewerSelector = new AgentReviewerSelector($boardService, $sessionService, $worktreesRoot);
        $launcher = new FakeAgentClientLauncher(AgentClient::CLAUDE);
        $registry = new AgentClientLauncherRegistry();
        $registry->register($launcher);

        $driver = new FakeSessionDriver();
        $driver->setAlive('r99', true);
        $driver->setAttached('r99', false);

        $capturedHasMoreCandidates = null;
        $prompter = static function (AgentSession $existing, BoardEntry $entry, bool $hasMoreCandidates) use (&$capturedHasMoreCandidates): WaOccupantChoice {
            $capturedHasMoreCandidates = $hasMoreCandidates;
            return WaOccupantChoice::Quit;
        };

        $cmd = new AgentStartCommand(
            $dir,
            $worktreesRoot,
            $boardPath,
            $registry,
            new AgentCodeService($worktreesRoot, $boardPath, $boardService, $sessionService),
            $sessionService,
            new AgentContextBuilder($dir, $boardPath, $boardService),
            (new \ReflectionClass(BacklogWorktreeService::class))->newInstanceWithoutConstructor(),
            $reviewerSelector,
            new AgentDeveloperSelector($boardService),
            $boardService,
            $driver,
            new FakeProcessSignaler(),
            new FakeProcessRunner(),
            new FakeBacklogCommandRunner(),
            new NullEntryRebaseService(),
            null,
            $this->buildLaunchPromptResolver(),
            $prompter,
        );

        $previousCwd = getcwd();
        try {
            $cmd->handle(['claude'], ['reviewer' => true, 'code' => 'r01']);
        } catch (\Throwable $e) {
            echo "FAIL testPassOptionNotOfferedForSingleCandidate: unexpected " . get_class($e) . ': ' . $e->getMessage() . "\n";
            return 1;
        } finally {
            if ($previousCwd !== false) {
                chdir($previousCwd);
            }
        }

        if ($capturedHasMoreCandidates !== false) {
            echo "FAIL testPassOptionNotOfferedForSingleCandidate: expected hasMoreCandidates=false for single candidate, got "
                . var_export($capturedHasMoreCandidates, true) . "\n";
            return 1;
        }

        echo "OK testPassOptionNotOfferedForSingleCandidate\n";
        return 0;
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
