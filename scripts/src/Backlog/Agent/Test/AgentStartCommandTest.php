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
use SoManAgent\Script\Backlog\Agent\Enum\AgentRole;
use SoManAgent\Script\Backlog\Agent\Exception\ActiveSessionException;
use SoManAgent\Script\Backlog\Agent\Exception\ClientNotInstalledException;
use SoManAgent\Script\Backlog\Agent\Service\AgentCodeService;
use SoManAgent\Script\Backlog\Agent\Service\AgentContextBuilder;
use SoManAgent\Script\Backlog\Agent\Service\AgentDeveloperSelector;
use SoManAgent\Script\Backlog\Agent\Service\AgentLaunchPromptResolver;
use SoManAgent\Script\Backlog\Agent\Service\AgentModelResolver;
use SoManAgent\Script\Backlog\Agent\Service\AgentReviewerSelector;
use SoManAgent\Script\Backlog\Agent\Service\AgentSessionService;
use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Service\BacklogWorktreeService;
use SoManAgent\Script\Backlog\Service\EntryRebaseResult;
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

    private function testTierOverrideIsForwardedToLauncher(): int
    {
        $projectRoot = $this->createGitProject('model-tier');
        $this->writeBoard($projectRoot . '/local/backlog-board.md', [
            '- tier-feature',
            '  meta:',
            '    kind: feature',
            '    feature: tier-feature',
            '    branch: feat/tier-feature',
            '    stage: development',
            '    agent: d11',
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
        $this->writeBoard($projectRoot . '/local/backlog-board.md', [
            '- effort-feature',
            '  meta:',
            '    kind: feature',
            '    feature: effort-feature',
            '    branch: feat/effort-feature',
            '    stage: development',
            '    agent: d12',
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
        $this->writeBoard($projectRoot . '/local/backlog-board.md', [
            '- gemini-feature',
            '  meta:',
            '    kind: feature',
            '    feature: gemini-feature',
            '    branch: feat/gemini-feature',
            '    stage: development',
            '    agent: d13',
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

    private function testReviewerModeCallsReviewNextWhenTakingEntry(): int
    {
        // A reviewer picking up a fresh review entry must delegate the review→reviewing
        // transition to BacklogCommandRunner::reviewNext(), not mutate the board directly.
        $dir = $this->scratchDir('reviewer-takes');
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
        $registry = new AgentClientLauncherRegistry();
        $registry->register($launcher);

        $fakeRunner = new FakeBacklogCommandRunner();
        // Simulate what review-next does: transition to reviewing so the reloaded board matches.
        $fakeRunner->onReviewNext = static function (string $reviewerCode, string $entryRef) use ($boardService, $boardPath): void {
            $board = $boardService->loadBoard($boardPath);
            foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $entry) {
                if ($entry->getFeature() === 'crypto-feature') {
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
            new AgentCodeService($dir, $worktreesRoot, $boardPath, $boardService, $sessionService, new FakeProcessSignaler()),
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
        if ($fakeRunner->calls[0]['reviewerCode'] !== 'r01' || $fakeRunner->calls[0]['entryRef'] !== 'crypto-feature') {
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

        $fakeRunner = new FakeBacklogCommandRunner();
        // Simulate review-next: set stage to reviewing in the board file.
        $fakeRunner->onReviewNext = static function (string $reviewerCode, string $entryRef) use ($boardService, $boardPath): void {
            $board = $boardService->loadBoard($boardPath);
            foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $entry) {
                if ($entry->getFeature() === 'crypto-feature') {
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
                if ($entry->getFeature() === 'crypto-feature') {
                    $entry->setStage(BacklogBoard::STAGE_IN_REVIEW);
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
            new AgentCodeService($dir, $worktreesRoot, $boardPath, $boardService, $sessionService, new FakeProcessSignaler()),
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
        if ($cancelCall['reviewerCode'] !== 'r01' || $cancelCall['entryRef'] !== 'crypto-feature') {
            echo "FAIL testReviewerModeRollsBackViaCancelWhenPreparationFails: unexpected cancel args: "
                . json_encode($cancelCall) . "\n";
            return 1;
        }

        // Board must be back at stage=review (simulated by fakeRunner callbacks)
        $reloaded = $boardService->loadBoard($boardPath);
        $entry = $reloaded->getEntries(BacklogBoard::SECTION_ACTIVE)[0] ?? null;
        if ($entry === null || $entry->getStage() !== BacklogBoard::STAGE_IN_REVIEW || $entry->getReviewer() !== null) {
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
                if ($entry->getFeature() === 'crypto-feature') {
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
            new AgentCodeService($dir, $worktreesRoot, $boardPath, $boardService, $sessionService, new FakeProcessSignaler()),
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
                if ($entry->getFeature() === 'crypto-feature') {
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
                if ($entry->getFeature() === 'crypto-feature') {
                    $entry->setStage(BacklogBoard::STAGE_IN_REVIEW);
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
            new AgentCodeService($dir, $worktreesRoot, $boardPath, $boardService, $sessionService, new FakeProcessSignaler()),
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
            new AgentDeveloperSelector($boardService),
            $boardService,
            new FakeSessionDriver(),
            new FakeProcessSignaler(),
            $shellRunner,
            new FakeBacklogCommandRunner(),
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
        $this->writeBoard($boardPath, [
            '- reset-feature',
            '  meta:',
            '    kind: feature',
            '    feature: reset-feature',
            '    branch: feat/reset-feature',
            '    stage: development',
            '    agent: d06',
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
            new AgentCodeService($projectRoot, $worktreesRoot, $boardPath, $boardService, $sessionService, new FakeProcessSignaler()),
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
        $boardPath = $projectRoot . '/local/backlog-board.md';
        $worktree = $worktreesRoot . '/d07';
        $this->writeBoard($boardPath, [
            '- detach-feature',
            '  meta:',
            '    kind: feature',
            '    feature: detach-feature',
            '    branch: feat/detach-feature',
            '    stage: development',
            '    agent: d07',
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
            new AgentCodeService($projectRoot, $worktreesRoot, $boardPath, $boardService, $sessionService, new FakeProcessSignaler()),
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
        // start --developer must call work-start with the task's entry ref.
        $projectRoot = $this->createGitProject('dev-auto-pick');
        $worktreesRoot = $projectRoot . '/.agent-worktrees';
        $boardPath = $projectRoot . '/local/backlog-board.md';

        $this->writeBoard($boardPath, []);
        file_put_contents($boardPath,
            "# Test backlog\n\n## To do\n\n"
            . "- [feat][my-feature] Auto-pick task\n\n"
            . "## In progress\n\n\n## Suggestions\n"
        );

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
            new AgentCodeService($projectRoot, $worktreesRoot, $boardPath, $boardService, $sessionService, new FakeProcessSignaler()),
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
            echo "FAIL testDeveloperAutoPicksFirstQueuedTask: work-start was not called\n";
            return 1;
        }
        if ($workStartCall['developerCode'] !== 'd08' || $workStartCall['entryRef'] !== 'my-feature') {
            echo "FAIL testDeveloperAutoPicksFirstQueuedTask: unexpected work-start args: "
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
        // start --developer must refuse with a clear error and must not call work-start.
        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $sessionService = new AgentSessionService($this->tmpDir);
        $launcher = new FakeAgentClientLauncher(AgentClient::CLAUDE);
        $registry = new AgentClientLauncherRegistry();
        $registry->register($launcher);
        $fakeRunner = new FakeBacklogCommandRunner();

        $boardPath = $this->tmpDir . '/board-empty.md';
        $this->writeBoard($boardPath, []);

        $cmd = new AgentStartCommand(
            $this->tmpDir,
            $this->tmpDir . '/worktrees',
            $boardPath,
            $registry,
            new AgentCodeService($this->tmpDir, $this->tmpDir . '/worktrees', $boardPath, $boardService, $sessionService, new FakeProcessSignaler()),
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
                echo "FAIL testDeveloperRefusesWhenTodoEmpty: work-start must not be called\n";
                return 1;
            }
        }

        echo "OK testDeveloperRefusesWhenTodoEmpty\n";
        return 0;
    }

    private function testDeveloperSkipsAutoPickWhenAlreadyHasActiveEntry(): int
    {
        // When the developer already has an active entry, start must not call work-start.
        $projectRoot = $this->createGitProject('dev-skip-pick');
        $worktreesRoot = $projectRoot . '/.agent-worktrees';
        $boardPath = $projectRoot . '/local/backlog-board.md';

        file_put_contents($boardPath,
            "# Test backlog\n\n## To do\n\n"
            . "- [feat][queued-feature] A queued task\n\n"
            . "## In progress\n\n"
            . "- active-feature\n"
            . "  meta:\n"
            . "    kind: feature\n"
            . "    feature: active-feature\n"
            . "    branch: feat/active-feature\n"
            . "    stage: development\n"
            . "    agent: d09\n\n"
            . "## Suggestions\n"
        );

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
            new AgentCodeService($projectRoot, $worktreesRoot, $boardPath, $boardService, $sessionService, new FakeProcessSignaler()),
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
                echo "FAIL testDeveloperSkipsAutoPickWhenAlreadyHasActiveEntry: work-start must not be called when entry already active\n";
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
        // When launcher.prepareWorktree() fails after work-start succeeded, the command
        // must call BacklogCommandRunner::entryRelease() to roll back the taken task.
        $projectRoot = $this->createGitProject('dev-rollback');
        $worktreesRoot = $projectRoot . '/.agent-worktrees';
        $boardPath = $projectRoot . '/local/backlog-board.md';

        file_put_contents($boardPath,
            "# Test backlog\n\n## To do\n\n"
            . "- [feat][rollback-feature] Task to auto-pick\n\n"
            . "## In progress\n\n\n## Suggestions\n"
        );

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
            new AgentCodeService($projectRoot, $worktreesRoot, $boardPath, $boardService, $sessionService, new FakeProcessSignaler()),
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
            echo "FAIL testDeveloperRollsBackViaEntryReleaseWhenPreparationFails: entry-release was not called\n";
            return 1;
        }
        if ($releaseCall['developerCode'] !== 'd10' || $releaseCall['entryRef'] !== 'rollback-feature') {
            echo "FAIL testDeveloperRollsBackViaEntryReleaseWhenPreparationFails: unexpected entry-release args: "
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
        $boardPath = $projectRoot . '/local/backlog-board.md';
        $worktree = $worktreesRoot . '/d14';

        $this->writeBoard($boardPath, [
            '- cwd-feature',
            '  meta:',
            '    kind: feature',
            '    feature: cwd-feature',
            '    branch: feat/cwd-feature',
            '    stage: development',
            '    agent: d14',
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
            new AgentCodeService($projectRoot, $worktreesRoot, $boardPath, $boardService, $sessionService, new FakeProcessSignaler()),
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
        $boardPath = $projectRoot . '/local/backlog-board.md';
        $worktree = $worktreesRoot . '/d15';

        $this->writeBoard($boardPath, [
            '- cwd-del-feature',
            '  meta:',
            '    kind: feature',
            '    feature: cwd-del-feature',
            '    branch: feat/cwd-del-feature',
            '    stage: development',
            '    agent: d15',
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
            new AgentCodeService($projectRoot, $worktreesRoot, $boardPath, $boardService, $sessionService, new FakeProcessSignaler()),
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
        $boardPath = $projectRoot . '/local/backlog-board.md';

        file_put_contents($boardPath,
            "# T\n\n## To do\n\n## In progress\n\n"
            . "- review-feature\n"
            . "  meta:\n"
            . "    kind: feature\n"
            . "    feature: review-feature\n"
            . "    branch: feat/review-feature\n"
            . "    stage: review\n"
            . "    agent: d20\n\n"
            . "## Suggestions\n"
        );

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
        $boardPath = $projectRoot . '/local/backlog-board.md';

        file_put_contents($boardPath,
            "# T\n\n## To do\n\n## In progress\n\n"
            . "- reviewing-feature\n"
            . "  meta:\n"
            . "    kind: feature\n"
            . "    feature: reviewing-feature\n"
            . "    branch: feat/reviewing-feature\n"
            . "    stage: reviewing\n"
            . "    agent: d21\n\n"
            . "## Suggestions\n"
        );

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
        $boardPath = $projectRoot . '/local/backlog-board.md';
        $worktree = $worktreesRoot . '/d22';

        file_put_contents($boardPath,
            "# T\n\n## To do\n\n## In progress\n\n"
            . "- approved-feature\n"
            . "  meta:\n"
            . "    kind: feature\n"
            . "    feature: approved-feature\n"
            . "    branch: feat/approved-feature\n"
            . "    stage: approved\n"
            . "    agent: d22\n\n"
            . "## Suggestions\n"
        );
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
            new AgentCodeService($projectRoot, $worktreesRoot, $boardPath, $boardService, $sessionService, new FakeProcessSignaler()),
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
            null,
            $this->buildLaunchPromptResolver(),
            $fakeRebase,
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
        $boardPath = $projectRoot . '/local/backlog-board.md';
        $worktree = $worktreesRoot . '/d23';

        file_put_contents($boardPath,
            "# T\n\n## To do\n\n## In progress\n\n"
            . "- conflict-feature\n"
            . "  meta:\n"
            . "    kind: feature\n"
            . "    feature: conflict-feature\n"
            . "    branch: feat/conflict-feature\n"
            . "    stage: approved\n"
            . "    agent: d23\n\n"
            . "## Suggestions\n"
        );
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
            new AgentCodeService($projectRoot, $worktreesRoot, $boardPath, $boardService, $sessionService, new FakeProcessSignaler()),
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
            null,
            $this->buildLaunchPromptResolver(),
            $fakeRebase,
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
            new AgentDeveloperSelector($boardService),
            $boardService,
            new FakeSessionDriver(),
            new FakeProcessSignaler(),
            new FakeProcessRunner(),
            new FakeBacklogCommandRunner(),
            $modelResolver,
            $this->buildLaunchPromptResolver(),
        );
    }

    private function buildProjectCommand(
        string $projectRoot,
        AgentClientLauncher $launcher,
        ?AgentModelResolver $modelResolver = null,
    ): AgentStartCommand {
        $boardPath = $projectRoot . '/local/backlog-board.md';
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
            new AgentCodeService($projectRoot, $worktreesRoot, $boardPath, $boardService, $sessionService, new FakeProcessSignaler()),
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
