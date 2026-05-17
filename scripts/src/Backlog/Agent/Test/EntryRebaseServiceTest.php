<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Test;

use SoManAgent\Script\Application;
use SoManAgent\Script\Backlog\Model\BoardEntry;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Service\EntryRebaseService;
use SoManAgent\Script\Client\ConsoleClient;
use SoManAgent\Script\Client\FilesystemClient;
use SoManAgent\Script\Client\GitClient;
use SoManAgent\Script\Console;
use SoManAgent\Script\RetryPolicy;
use SoManAgent\Script\Service\GitService;
use SoManAgent\Script\TextSlugger;

/**
 * Integration tests for {@see EntryRebaseService} using real git repositories.
 *
 * Each test creates an isolated git repository under a temporary directory.
 * The service runs with CWD set to the test repository so that relative paths
 * in git -C commands resolve correctly.
 *
 * Tests:
 * - already_up_to_date: source branch already contains target HEAD → no rebase
 * - clean rebase: target has new commits, no conflicts → rebase succeeds
 * - conflict: target and source have incompatible changes → conflict files reported
 * - target branch detection: feature (origin/main) vs task (parent feature branch)
 */
final class EntryRebaseServiceTest
{
    private string $tmpDir;

    private string $originalCwd;

    /**
     * @throws \RuntimeException
     */
    public function __construct()
    {
        $this->tmpDir = sys_get_temp_dir() . '/entry-rebase-svc-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
        $this->originalCwd = (string) getcwd();
    }

    public function __destruct()
    {
        chdir($this->originalCwd);
        $this->rmdir($this->tmpDir);
    }

    public function run(): int
    {
        $failed = 0;

        $failed += $this->testAlreadyUpToDate();
        $failed += $this->testCleanRebase();
        $failed += $this->testConflict();
        $failed += $this->testTaskUsesFeatureBranch();

        return $failed;
    }

    private function testAlreadyUpToDate(): int
    {
        // Feature branch is already based on the current origin/main HEAD → upToDate.
        $root = $this->scratchDir('up-to-date');
        $this->initRepoWithOrigin($root);
        $this->commit($root, 'file.txt', 'initial', 'init');
        $this->runShell("git -C {$root} push origin main");

        // Create feature branch from the initial commit.
        $this->runShell("git -C {$root} checkout -b feat/my-feature");
        $this->commit($root, 'feat.txt', 'feat', 'feat commit');

        // Advance main with a new commit.
        $this->runShell("git -C {$root} checkout main");
        $this->commit($root, 'main2.txt', 'main2', 'main commit 2');
        $this->runShell("git -C {$root} push origin main");

        // Rebase feature onto updated main — now feature is ahead of main.
        $this->runShell("git -C {$root} checkout feat/my-feature");
        $this->runShell("git -C {$root} rebase main");

        // Fetch so origin/main tracking ref is updated.
        $this->runShell("git -C {$root} fetch origin");

        $entry = new BoardEntry('Feature');
        $entry->setKind('feature');
        $entry->setFeature('my-feature');
        $entry->setBranch('feat/my-feature');

        $previousCwd = getcwd();
        chdir($root);
        try {
            $service = $this->buildService($root);
            // Worktree path is not needed for the upToDate case (returns before any worktree ops).
            $result = $service->rebase($entry, $root . '/worktrees/my-feature');
        } finally {
            chdir($previousCwd !== false ? $previousCwd : $this->originalCwd);
        }

        if (!$result->isUpToDate()) {
            echo "FAIL testAlreadyUpToDate: expected upToDate, got " . ($result->isRebased() ? 'rebased' : 'conflict') . "\n";
            return 1;
        }

        echo "OK testAlreadyUpToDate\n";
        return 0;
    }

    private function testCleanRebase(): int
    {
        // Feature branch is behind main → rebase succeeds cleanly and branch is pushed.
        $root = $this->scratchDir('clean-rebase');
        $worktree = $root . '/worktrees/clean-rebase';
        $this->initRepoWithOrigin($root);
        $this->commit($root, 'file.txt', 'initial', 'init');
        $this->runShell("git -C {$root} push origin main");

        // Create feature branch from first commit and stay on main afterward.
        $this->runShell("git -C {$root} checkout -b feat/clean-rebase");
        $this->commit($root, 'feat.txt', 'feat content', 'feat commit');
        $this->runShell("git -C {$root} push origin feat/clean-rebase");
        $this->runShell("git -C {$root} checkout main");

        // Add a new commit on main and push so origin/main is ahead of the feature branch.
        $this->commit($root, 'main2.txt', 'main2 content', 'main commit 2');
        $this->runShell("git -C {$root} push origin main");
        $this->runShell("git -C {$root} fetch origin");

        // Create git worktree that checks out the feature branch (not detached) so that
        // the rebase in the worktree updates the branch ref in the main repo.
        mkdir(dirname($worktree), 0755, true);
        $this->runShell("git -C {$root} worktree add {$worktree} feat/clean-rebase");

        $entry = new BoardEntry('Feature');
        $entry->setKind('feature');
        $entry->setFeature('clean-rebase');
        $entry->setBranch('feat/clean-rebase');

        $previousCwd = getcwd();
        chdir($root);
        try {
            $service = $this->buildService($root);
            $result = $service->rebase($entry, $worktree);
        } finally {
            chdir($previousCwd !== false ? $previousCwd : $this->originalCwd);
        }

        if ($result->isConflict()) {
            echo "FAIL testCleanRebase: unexpected conflict\n";
            return 1;
        }

        // Verify the rebase happened: feat/clean-rebase should now be on top of main.
        $mainHead = trim((string) shell_exec("git -C {$root} rev-parse main"));
        $featBase = trim((string) shell_exec("git -C {$root} merge-base main feat/clean-rebase"));

        if ($mainHead !== $featBase) {
            echo "FAIL testCleanRebase: feat/clean-rebase was not rebased onto main\n";
            echo "  main={$mainHead} featBase={$featBase}\n";
            return 1;
        }

        if (!$result->isRebased()) {
            echo "FAIL testCleanRebase: expected rebased result, got " . ($result->isUpToDate() ? 'up_to_date' : 'conflict') . "\n";
            return 1;
        }

        echo "OK testCleanRebase\n";
        return 0;
    }

    private function testConflict(): int
    {
        // Feature branch and main have conflicting changes on the same file.
        $root = $this->scratchDir('conflict');
        $worktree = $root . '/worktrees/conflicting';
        $this->initRepoWithOrigin($root);
        $this->commit($root, 'shared.txt', 'line1', 'init');
        $this->runShell("git -C {$root} push origin main");

        $this->runShell("git -C {$root} checkout -b feat/conflicting");
        $this->commit($root, 'shared.txt', 'feat version', 'feat commit');
        $this->runShell("git -C {$root} push origin feat/conflicting");

        $this->runShell("git -C {$root} checkout main");
        $this->commit($root, 'shared.txt', 'main version', 'main commit');
        $this->runShell("git -C {$root} push origin main");
        $this->runShell("git -C {$root} fetch origin");

        mkdir(dirname($worktree), 0755, true);
        $this->runShell("git -C {$root} worktree add {$worktree} feat/conflicting");

        $entry = new BoardEntry('Conflicting feature');
        $entry->setKind('feature');
        $entry->setFeature('conflicting');
        $entry->setBranch('feat/conflicting');

        $previousCwd = getcwd();
        chdir($root);
        try {
            $service = $this->buildService($root);
            $result = $service->rebase($entry, $worktree);
        } finally {
            chdir($previousCwd !== false ? $previousCwd : $this->originalCwd);
        }

        if (!$result->isConflict()) {
            echo "FAIL testConflict: expected conflict, got " . ($result->isUpToDate() ? 'up_to_date' : 'rebased') . "\n";
            return 1;
        }

        if ($result->getConflictFiles() === []) {
            echo "FAIL testConflict: expected non-empty conflict file list\n";
            return 1;
        }

        echo "OK testConflict (files: " . implode(', ', $result->getConflictFiles()) . ")\n";
        return 0;
    }

    private function testTaskUsesFeatureBranch(): int
    {
        // Task entry → target must be the parent feature branch, not main.
        // updateMainBranch() is NOT called for tasks, so no remote is needed.
        $root = $this->scratchDir('task-feature-branch');
        $worktree = $root . '/worktrees/task';
        $this->initRepo($root);
        $this->commit($root, 'file.txt', 'initial', 'init');

        $this->runShell("git -C {$root} checkout -b feat/parent-feature");
        $this->commit($root, 'feat.txt', 'feat', 'feat commit');

        $this->runShell("git -C {$root} checkout -b task/my-task");
        $this->commit($root, 'task.txt', 'task', 'task commit');

        $this->runShell("git -C {$root} checkout feat/parent-feature");
        // Add extra commit on feature branch so the task needs to rebase.
        $this->commit($root, 'feat2.txt', 'feat2', 'feat commit 2');
        // Stay on feat/parent-feature so the worktree can check out task/my-task.

        mkdir(dirname($worktree), 0755, true);
        $this->runShell("git -C {$root} worktree add {$worktree} task/my-task");

        $entry = new BoardEntry('My task');
        $entry->setKind('task');
        $entry->setFeature('parent-feature');
        $entry->setTask('my-task');
        $entry->setBranch('task/my-task');
        $entry->setFeatureBranch('feat/parent-feature');

        $previousCwd = getcwd();
        chdir($root);
        try {
            $service = $this->buildService($root);
            $result = $service->rebase($entry, $worktree);
        } finally {
            chdir($previousCwd !== false ? $previousCwd : $this->originalCwd);
        }

        if ($result->isConflict()) {
            echo "FAIL testTaskUsesFeatureBranch: unexpected conflict\n";
            return 1;
        }

        // Verify the task is now based on the parent feature, not on main.
        $featureHead = trim((string) shell_exec("git -C {$root} rev-parse feat/parent-feature"));
        $taskBase = trim((string) shell_exec("git -C {$root} merge-base feat/parent-feature task/my-task"));

        if ($featureHead !== $taskBase) {
            echo "FAIL testTaskUsesFeatureBranch: task was not rebased onto feat/parent-feature\n";
            echo "  featureHead={$featureHead} taskBase={$taskBase}\n";
            return 1;
        }

        echo "OK testTaskUsesFeatureBranch (" . $result->getTargetBranch() . ")\n";
        return 0;
    }

    private function buildService(string $projectRoot): EntryRebaseService
    {
        $app = Application::getInstance();
        $consoleClient = new ConsoleClient($projectRoot, false, $app, static function (string $m): void {});
        $git = new GitClient(false, $consoleClient, new RetryPolicy(0, 0));
        $gitService = new GitService(false, Console::getInstance(), $git, static function (string $m): void {});
        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);

        return new EntryRebaseService($boardService, $gitService);
    }

    private function initRepo(string $root): void
    {
        $this->runShell("git -C {$root} init --initial-branch=main");
        $this->runShell("git -C {$root} config user.email test@example.invalid");
        $this->runShell("git -C {$root} config user.name 'Test'");
    }

    /**
     * Initialises a repo with a self-referential origin so git fetch/push work in tests.
     */
    private function initRepoWithOrigin(string $root): void
    {
        $this->initRepo($root);
        // file:// origin pointing to the repo itself enables push/fetch without a real remote
        $this->runShell("git -C {$root} remote add origin " . escapeshellarg('file://' . $root));
    }

    private function commit(string $root, string $file, string $content, string $message): void
    {
        file_put_contents($root . '/' . $file, $content);
        $this->runShell("git -C {$root} add " . escapeshellarg($file));
        $this->runShell("git -C {$root} commit -m " . escapeshellarg($message));
    }

    private function scratchDir(string $label): string
    {
        $path = $this->tmpDir . '/' . $label . '-' . uniqid('', true);
        mkdir($path, 0755, true);
        return $path;
    }

    private function runShell(string $command): void
    {
        $output = [];
        $code = 0;
        exec($command . ' 2>&1', $output, $code);
        if ($code !== 0) {
            throw new \RuntimeException(sprintf(
                "Command failed (exit %d): %s\n%s",
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
