<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Test;

use SoManAgent\Script\Application;
use SoManAgent\Script\Backlog\Command\BacklogEntryRebaseCommand;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Service\BacklogPresenter;
use SoManAgent\Script\Backlog\Service\BacklogWorktreeService;
use SoManAgent\Script\Backlog\Service\EntryRebaseResult;
use SoManAgent\Script\Client\ConsoleClient;
use SoManAgent\Script\Client\FilesystemClient;
use SoManAgent\Script\Console;
use SoManAgent\Script\RetryPolicy;
use SoManAgent\Script\TextSlugger;

/**
 * Unit tests for {@see BacklogEntryRebaseCommand}.
 *
 * Verifies that the command relays exit codes and messages from {@see EntryRebaseService}:
 * - up_to_date  → prints message, exits 0 (no exception)
 * - rebased     → prints message, exits 0 (no exception)
 * - conflict    → prints file list, throws RuntimeException (exits non-zero)
 *
 * Also covers the CLI validation layer:
 * - missing slug → RuntimeException
 * - no agent assigned → RuntimeException
 * - worktree not found → RuntimeException
 * - --dry-run → does not call the service
 */
final class EntryRebaseCommandTest
{
    private string $tmpDir;

    /**
     * Creates a temporary directory for test fixtures.
     */
    public function __construct()
    {
        $this->tmpDir = sys_get_temp_dir() . '/entry-rebase-cmd-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
    }

    /**
     * Removes the temporary directory on teardown.
     */
    public function __destruct()
    {
        $this->rmdir($this->tmpDir);
    }

    /**
     * Runs all test cases and returns the cumulative failure count.
     */
    public function run(): int
    {
        $failed = 0;

        $failed += $this->testMissingSlugThrows();
        $failed += $this->testNoAgentAssignedThrows();
        $failed += $this->testWorktreeNotFoundThrows();
        $failed += $this->testUpToDatePrintsMessageAndDoesNotThrow();
        $failed += $this->testRebasedPrintsMessageAndDoesNotThrow();
        $failed += $this->testConflictPrintsFilesAndThrows();
        $failed += $this->testDryRunDoesNotCallService();

        return $failed;
    }

    private function testMissingSlugThrows(): int
    {
        $dir = $this->scratchDir('missing-slug');
        $board = $dir . '/board.yaml';
        $this->writeEmptyBoard($board);

        $fake = new FakeEntryRebaseService(EntryRebaseResult::upToDate('origin/main'));
        $cmd = $this->buildCommand($dir, $board, $fake);

        $threw = false;
        try {
            $cmd->handle([], []);
        } catch (\RuntimeException $e) {
            $threw = str_contains($e->getMessage(), 'entry-rebase requires <slug>');
        }

        if (!$threw) {
            echo "FAIL testMissingSlugThrows: expected RuntimeException with 'entry-rebase requires <slug>'\n";
            return 1;
        }

        echo "OK testMissingSlugThrows\n";
        return 0;
    }

    private function testNoAgentAssignedThrows(): int
    {
        $dir = $this->scratchDir('no-agent');
        $board = $dir . '/board.yaml';
        $this->writeFeatureBoard($board, 'no-agent-feature', 'feat/no-agent-feature', 'approved', null);

        $fake = new FakeEntryRebaseService(EntryRebaseResult::upToDate('origin/main'));
        $cmd = $this->buildCommand($dir, $board, $fake);

        $threw = false;
        try {
            ob_start();
            $cmd->handle(['no-agent-feature'], []);
            ob_end_clean();
        } catch (\RuntimeException $e) {
            ob_end_clean();
            $threw = str_contains($e->getMessage(), 'no agent is assigned');
        }

        if (!$threw) {
            echo "FAIL testNoAgentAssignedThrows: expected 'no agent is assigned' error\n";
            return 1;
        }

        echo "OK testNoAgentAssignedThrows\n";
        return 0;
    }

    private function testWorktreeNotFoundThrows(): int
    {
        $dir = $this->scratchDir('no-worktree');
        $worktreesRoot = $dir . '/worktrees';
        $board = $dir . '/board.yaml';
        $this->writeFeatureBoard($board, 'my-feature', 'feat/my-feature', 'approved', 'd10');

        $fake = new FakeEntryRebaseService(EntryRebaseResult::upToDate('origin/main'));
        $cmd = $this->buildCommand($dir, $board, $fake, $worktreesRoot);

        $threw = false;
        try {
            ob_start();
            $cmd->handle(['my-feature'], []);
            ob_end_clean();
        } catch (\RuntimeException $e) {
            ob_end_clean();
            $threw = str_contains($e->getMessage(), 'does not exist') || str_contains($e->getMessage(), 'worktree');
        }

        if (!$threw) {
            echo "FAIL testWorktreeNotFoundThrows: expected worktree-not-found error\n";
            return 1;
        }

        echo "OK testWorktreeNotFoundThrows\n";
        return 0;
    }

    private function testUpToDatePrintsMessageAndDoesNotThrow(): int
    {
        $dir = $this->scratchDir('up-to-date');
        $worktreesRoot = $dir . '/worktrees';
        mkdir($worktreesRoot . '/d10', 0755, true);
        $board = $dir . '/board.yaml';
        $this->writeFeatureBoard($board, 'up-feature', 'feat/up-feature', 'approved', 'd10');

        $fake = new FakeEntryRebaseService(EntryRebaseResult::upToDate('origin/main'));
        $cmd = $this->buildCommand($dir, $board, $fake, $worktreesRoot);

        $threw = false;
        $output = '';
        try {
            ob_start();
            $cmd->handle(['up-feature'], []);
            $output = ob_get_clean();
        } catch (\RuntimeException $e) {
            ob_get_clean();
            $threw = true;
            echo "FAIL testUpToDatePrintsMessageAndDoesNotThrow: unexpected exception: " . $e->getMessage() . "\n";
        }

        if ($threw) {
            return 1;
        }

        if ($fake->lastCall === null) {
            echo "FAIL testUpToDatePrintsMessageAndDoesNotThrow: rebase service was not called\n";
            return 1;
        }

        if (!str_contains((string) $output, 'Entry-ref: up-feature') || !str_contains((string) $output, 'Branch: feat/up-feature')) {
            echo "FAIL testUpToDatePrintsMessageAndDoesNotThrow: expected Entry-ref and Branch in output, got: {$output}\n";
            return 1;
        }

        echo "OK testUpToDatePrintsMessageAndDoesNotThrow\n";
        return 0;
    }

    private function testRebasedPrintsMessageAndDoesNotThrow(): int
    {
        $dir = $this->scratchDir('rebased');
        $worktreesRoot = $dir . '/worktrees';
        mkdir($worktreesRoot . '/d10', 0755, true);
        $board = $dir . '/board.yaml';
        $this->writeFeatureBoard($board, 'rebased-feature', 'feat/rebased-feature', 'approved', 'd10');

        $fake = new FakeEntryRebaseService(EntryRebaseResult::rebased('origin/main'));
        $cmd = $this->buildCommand($dir, $board, $fake, $worktreesRoot);

        $threw = false;
        try {
            ob_start();
            $cmd->handle(['rebased-feature'], []);
            ob_get_clean();
        } catch (\RuntimeException $e) {
            ob_get_clean();
            $threw = true;
            echo "FAIL testRebasedPrintsMessageAndDoesNotThrow: unexpected exception: " . $e->getMessage() . "\n";
        }

        if ($threw) {
            return 1;
        }

        echo "OK testRebasedPrintsMessageAndDoesNotThrow\n";
        return 0;
    }

    private function testConflictPrintsFilesAndThrows(): int
    {
        $dir = $this->scratchDir('conflict');
        $worktreesRoot = $dir . '/worktrees';
        mkdir($worktreesRoot . '/d10', 0755, true);
        $board = $dir . '/board.yaml';
        $this->writeFeatureBoard($board, 'conflict-feature', 'feat/conflict-feature', 'approved', 'd10');

        $fake = new FakeEntryRebaseService(EntryRebaseResult::conflict('origin/main', ['src/Foo.php', 'src/Bar.php']));
        $cmd = $this->buildCommand($dir, $board, $fake, $worktreesRoot);

        $threw = false;
        $output = '';
        try {
            ob_start();
            $cmd->handle(['conflict-feature'], []);
            $output = ob_get_clean() ?: '';
        } catch (\RuntimeException $e) {
            $output = ob_get_clean() ?: '';
            $threw = str_contains($e->getMessage(), 'Resolve conflicts') || str_contains($e->getMessage(), 'conflict');
        }

        if (!$threw) {
            echo "FAIL testConflictPrintsFilesAndThrows: expected RuntimeException for conflict\n";
            return 1;
        }

        if (str_contains($output, '[OK]')) {
            echo "FAIL testConflictPrintsFilesAndThrows: output must not contain [OK] on conflict, got: {$output}\n";
            return 1;
        }

        echo "OK testConflictPrintsFilesAndThrows\n";
        return 0;
    }

    private function testDryRunDoesNotCallService(): int
    {
        $dir = $this->scratchDir('dry-run');
        $worktreesRoot = $dir . '/worktrees';
        mkdir($worktreesRoot . '/d10', 0755, true);
        $board = $dir . '/board.yaml';
        $this->writeFeatureBoard($board, 'dry-feature', 'feat/dry-feature', 'approved', 'd10');

        $fake = new FakeEntryRebaseService(EntryRebaseResult::upToDate('origin/main'));
        $cmd = $this->buildCommand($dir, $board, $fake, $worktreesRoot);

        ob_start();
        $cmd->handle(['dry-feature'], ['dry-run' => true]);
        $output = ob_get_clean();

        if ($fake->lastCall !== null) {
            echo "FAIL testDryRunDoesNotCallService: rebase service must not be called in dry-run mode\n";
            return 1;
        }

        if (!str_contains((string) $output, 'dry-run')) {
            echo "FAIL testDryRunDoesNotCallService: expected [dry-run] in output, got: {$output}\n";
            return 1;
        }

        if (!str_contains((string) $output, 'Entry-ref: dry-feature') || !str_contains((string) $output, 'Branch: feat/dry-feature')) {
            echo "FAIL testDryRunDoesNotCallService: expected Entry-ref and Branch in output, got: {$output}\n";
            return 1;
        }

        echo "OK testDryRunDoesNotCallService\n";
        return 0;
    }

    private function buildCommand(
        string $dir,
        string $boardPath,
        FakeEntryRebaseService $rebaseService,
        ?string $worktreesRoot = null,
    ): BacklogEntryRebaseCommand {
        $app = Application::getInstance();
        $console = new ConsoleClient($dir, false, $app, static function (string $m): void {});
        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $presenter = new BacklogPresenter(Console::getInstance(), $console, $boardService);

        $worktreeService = $this->buildWorktreeService($worktreesRoot ?? ($dir . '/worktrees'));

        $cmd = new BacklogEntryRebaseCommand($presenter, false, $dir, $boardService, $worktreeService, $rebaseService);
        $cmd->setBoardPath($boardPath);

        return $cmd;
    }

    private function buildWorktreeService(string $worktreesRoot): BacklogWorktreeService
    {
        $instance = (new \ReflectionClass(BacklogWorktreeService::class))->newInstanceWithoutConstructor();
        $prop = (new \ReflectionClass(BacklogWorktreeService::class))->getProperty('worktreesRoot');
        $prop->setAccessible(true);
        $prop->setValue($instance, $worktreesRoot);

        return $instance;
    }

    private function scratchDir(string $label): string
    {
        $path = $this->tmpDir . '/' . $label . '-' . uniqid('', true);
        mkdir($path, 0755, true);

        return $path;
    }

    private function writeEmptyBoard(string $path): void
    {
        file_put_contents($path, "version: 1\ntodo: []\nactive: []\n");
    }

    private function writeFeatureBoard(string $path, string $feature, string $branch, string $stage, ?string $agent): void
    {
        $agentLine = $agent !== null ? "  agent: {$agent}\n" : '';
        file_put_contents($path, <<<YAML
version: 1
todo: []
active:
- kind: feature
  stage: {$stage}
  feature: {$feature}
{$agentLine}  branch: {$branch}
  base: base-sha
  pr: none
  title: {$feature}

YAML);
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
