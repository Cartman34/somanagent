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
use SoManAgent\Script\Client\FilesystemClient;
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

    public function __construct()
    {
        $this->tmpDir = sys_get_temp_dir() . '/backlog-agent-start-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
    }

    public function __destruct()
    {
        $this->rmdir($this->tmpDir);
    }

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
            $threw = str_contains($e->getMessage(), '--reset is not allowed with --reviewer');
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

        $codeService = new AgentCodeService($dir, $worktreesRoot, $boardPath, $boardService, $sessionService);
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
