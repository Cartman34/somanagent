<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Test;

use Sowapps\SoManAgent\Script\Backlog\Service\EntryRebaseResult;
use Sowapps\SoManAgent\Script\Backlog\Command\BacklogEntryRebaseCommand;
use Sowapps\SoManAgent\Script\Application;
use Sowapps\SoManAgent\Script\Client\ConsoleClient;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogBoardService;
use Sowapps\SoManAgent\Script\TextSlugger;
use Sowapps\SoManAgent\Script\Client\FilesystemClient;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogPresenter;
use Sowapps\SoManAgent\Script\Console;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogWorktreeService;

use Sowapps\SoManAgent\Script\Backlog\Agent\Test\Support\FakeEntryRebaseService;
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
    private const NO_AGENT_FEATURE = 'no-agent-feature';

    private const MY_FEATURE = 'my-feature';

    private const UP_FEATURE = 'up-feature';

    private const REBASED_FEATURE = 'rebased-feature';

    private const CONFLICT_FEATURE = 'conflict-feature';

    private const DRY_FEATURE = 'dry-feature';

    private const DRY_RUN = 'dry-run';

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
            $threw = str_contains($e->getMessage(), 'rebase requires <slug>');
        }

        if (!$threw) {
            echo "FAIL testMissingSlugThrows: expected RuntimeException with 'rebase requires <slug>'\n";
            return 1;
        }

        echo "OK testMissingSlugThrows\n";
        return 0;
    }

    private function testNoAgentAssignedThrows(): int
    {
        $dir = $this->scratchDir('no-agent');
        $board = $dir . '/board.yaml';
        $this->writeFeatureBoard($board, self::NO_AGENT_FEATURE, 'feat/no-agent-feature', 'approved', null);

        $fake = new FakeEntryRebaseService(EntryRebaseResult::upToDate('origin/main'));
        $cmd = $this->buildCommand($dir, $board, $fake);

        $threw = false;
        try {
            ob_start();
            $cmd->handle([self::NO_AGENT_FEATURE], []);
            ob_end_clean();
        } catch (\RuntimeException $e) {
            ob_end_clean();
            $threw = str_contains($e->getMessage(), 'no developer is assigned');
        }

        if (!$threw) {
            echo "FAIL testNoAgentAssignedThrows: expected 'no developer is assigned' error\n";
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
        $this->writeFeatureBoard($board, self::MY_FEATURE, 'feat/' . self::MY_FEATURE, 'approved', 'd10');

        $fake = new FakeEntryRebaseService(EntryRebaseResult::upToDate('origin/main'));
        $cmd = $this->buildCommand($dir, $board, $fake, $worktreesRoot);

        $threw = false;
        try {
            ob_start();
            $cmd->handle([self::MY_FEATURE], []);
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
        $this->writeFeatureBoard($board, self::UP_FEATURE, 'feat/' . self::UP_FEATURE, 'approved', 'd10');

        $fake = new FakeEntryRebaseService(EntryRebaseResult::upToDate('origin/main'));
        $cmd = $this->buildCommand($dir, $board, $fake, $worktreesRoot);

        $threw = false;
        $output = '';
        try {
            ob_start();
            $cmd->handle([self::UP_FEATURE], []);
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
        $this->writeFeatureBoard($board, self::REBASED_FEATURE, 'feat/' . self::REBASED_FEATURE, 'approved', 'd10');

        $fake = new FakeEntryRebaseService(EntryRebaseResult::rebased('origin/main'));
        $cmd = $this->buildCommand($dir, $board, $fake, $worktreesRoot);

        $threw = false;
        try {
            ob_start();
            $cmd->handle([self::REBASED_FEATURE], []);
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
        $this->writeFeatureBoard($board, self::CONFLICT_FEATURE, 'feat/' . self::CONFLICT_FEATURE, 'approved', 'd10');

        $fake = new FakeEntryRebaseService(EntryRebaseResult::conflict('origin/main', ['src/Foo.php', 'src/Bar.php']));
        $cmd = $this->buildCommand($dir, $board, $fake, $worktreesRoot);

        $threw = false;
        $output = '';
        try {
            ob_start();
            $cmd->handle([self::CONFLICT_FEATURE], []);
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
        $dir = $this->scratchDir(self::DRY_RUN);
        $worktreesRoot = $dir . '/worktrees';
        mkdir($worktreesRoot . '/d10', 0755, true);
        $board = $dir . '/board.yaml';
        $this->writeFeatureBoard($board, self::DRY_FEATURE, 'feat/' . self::DRY_FEATURE, 'approved', 'd10');

        $fake = new FakeEntryRebaseService(EntryRebaseResult::upToDate('origin/main'));
        $cmd = $this->buildCommand($dir, $board, $fake, $worktreesRoot);

        ob_start();
        $cmd->handle([self::DRY_FEATURE], [self::DRY_RUN => true]);
        $output = ob_get_clean();

        if ($fake->lastCall !== null) {
            echo "FAIL testDryRunDoesNotCallService: rebase service must not be called in dry-run mode\n";
            return 1;
        }

        if (!str_contains((string) $output, self::DRY_RUN)) {
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
        $agentLine = $agent !== null ? "  developer: {$agent}\n" : '';
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
