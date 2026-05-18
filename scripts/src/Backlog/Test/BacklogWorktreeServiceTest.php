<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Test;

use SoManAgent\Script\Application;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Service\BacklogWorktreeService;
use SoManAgent\Script\Client\ConsoleClient;
use SoManAgent\Script\Client\FilesystemClient;
use SoManAgent\Script\Client\GitClient;
use SoManAgent\Script\Client\ProjectScriptClient;
use SoManAgent\Script\RetryPolicy;

/**
 * Unit coverage for managed worktree preparation.
 */
final class BacklogWorktreeServiceTest
{
    private string $projectRoot;

    /**
     * Resolves the project root from the test file location.
     */
    public function __construct()
    {
        $this->projectRoot = dirname(__DIR__, 4);
    }

    /**
     * Runs all worktree service tests and returns the number of failures.
     */
    public function run(): int
    {
        $failed = 0;

        $failed += $this->testPrepareAgentWorktreeCreatesLocalWorkingDirectories();
        $failed += $this->testPrepareAgentWorktreeLocalWorkingDirectoriesAreIdempotent();
        $failed += $this->testPrepareAgentWorktreeDoesNotWriteUnderGitInternals();
        $failed += $this->testPrepareAgentWorktreeInstallsPreCommitHook();
        $failed += $this->testPrepareAgentWorktreePreCommitHookIsIdempotent();
        $failed += $this->testRunReviewScriptPersistsFullOutputAndPrintsPointerOnSuccess();
        $failed += $this->testRunReviewScriptPersistsFullOutputAndPrintsPointerBeforeFailure();

        return $failed;
    }

    private function testRunReviewScriptPersistsFullOutputAndPrintsPointerOnSuccess(): int
    {
        $agent = 'test-d10-review-pass-' . $this->uniqueToken();
        $worktreesRoot = $this->projectRoot . '/local/tests/worktree-service';
        $worktree = $worktreesRoot . '/' . $agent;
        $fullReport = "FULL-REPORT-START\n" . str_repeat('x', 4096) . "\nFULL-REPORT-END";

        try {
            $this->createReviewScriptFixture($worktree, $fullReport, 0);
            $service = $this->createService($worktreesRoot);

            ob_start();
            $service->runReviewScript($worktree);
            $stdout = (string) ob_get_clean();

            $resultPath = $worktree . '/local/backlog-review-result.txt';
            if ((string) file_get_contents($resultPath) !== $fullReport) {
                echo "FAIL testRunReviewScriptPersistsFullOutputAndPrintsPointerOnSuccess: full report was not persisted\n";
                return 1;
            }
            if (!str_contains($stdout, 'Mechanical review status: PASS')) {
                echo "FAIL testRunReviewScriptPersistsFullOutputAndPrintsPointerOnSuccess: PASS status missing\n";
                return 1;
            }
            if (!str_contains($stdout, 'Review report saved to local/backlog-review-result.txt')) {
                echo "FAIL testRunReviewScriptPersistsFullOutputAndPrintsPointerOnSuccess: pointer missing\n";
                return 1;
            }
            if (str_contains($stdout, 'FULL-REPORT-START') || str_contains($stdout, 'FULL-REPORT-END')) {
                echo "FAIL testRunReviewScriptPersistsFullOutputAndPrintsPointerOnSuccess: stdout replayed the full report\n";
                return 1;
            }
        } finally {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            $this->cleanupWorktree($worktree);
        }

        echo "OK testRunReviewScriptPersistsFullOutputAndPrintsPointerOnSuccess\n";
        return 0;
    }

    private function testRunReviewScriptPersistsFullOutputAndPrintsPointerBeforeFailure(): int
    {
        $agent = 'test-d10-review-fail-' . $this->uniqueToken();
        $worktreesRoot = $this->projectRoot . '/local/tests/worktree-service';
        $worktree = $worktreesRoot . '/' . $agent;
        $fullReport = "FAIL-REPORT-START\n" . str_repeat('y', 4096) . "\nFAIL-REPORT-END";

        try {
            $this->createReviewScriptFixture($worktree, $fullReport, 7);
            $service = $this->createService($worktreesRoot);

            ob_start();
            try {
                $service->runReviewScript($worktree);
                ob_end_clean();
                echo "FAIL testRunReviewScriptPersistsFullOutputAndPrintsPointerBeforeFailure: expected exception\n";
                return 1;
            } catch (\RuntimeException $exception) {
                $stdout = (string) ob_get_clean();
                if (!str_contains($exception->getMessage(), 'Review script failed with exit code 7.')) {
                    echo "FAIL testRunReviewScriptPersistsFullOutputAndPrintsPointerBeforeFailure: unexpected exception {$exception->getMessage()}\n";
                    return 1;
                }
            }

            $resultPath = $worktree . '/local/backlog-review-result.txt';
            if ((string) file_get_contents($resultPath) !== $fullReport) {
                echo "FAIL testRunReviewScriptPersistsFullOutputAndPrintsPointerBeforeFailure: full report was not persisted\n";
                return 1;
            }
            if (!str_contains($stdout, 'Mechanical review status: FAIL')) {
                echo "FAIL testRunReviewScriptPersistsFullOutputAndPrintsPointerBeforeFailure: FAIL status missing before exception\n";
                return 1;
            }
            if (!str_contains($stdout, 'Review report saved to local/backlog-review-result.txt')) {
                echo "FAIL testRunReviewScriptPersistsFullOutputAndPrintsPointerBeforeFailure: pointer missing before exception\n";
                return 1;
            }
            if (str_contains($stdout, 'FAIL-REPORT-START') || str_contains($stdout, 'FAIL-REPORT-END')) {
                echo "FAIL testRunReviewScriptPersistsFullOutputAndPrintsPointerBeforeFailure: stdout replayed the full report\n";
                return 1;
            }
        } finally {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            $this->cleanupWorktree($worktree);
        }

        echo "OK testRunReviewScriptPersistsFullOutputAndPrintsPointerBeforeFailure\n";
        return 0;
    }

    private function testPrepareAgentWorktreeDoesNotWriteUnderGitInternals(): int
    {
        $agent = 'test-d10-no-git-write-' . $this->uniqueToken();
        $worktreesRoot = $this->projectRoot . '/local/tests/worktree-service';
        $worktree = $worktreesRoot . '/' . $agent;

        try {
            $this->createExistingAgentRepository($worktree);
            $service = $this->createService($worktreesRoot);

            // Sentinel pre-existing exclude file the service must not modify or delete.
            $infoDir = $worktree . '/.git/info';
            if (!is_dir($infoDir) && !mkdir($infoDir, 0o755, true) && !is_dir($infoDir)) {
                echo "FAIL testPrepareAgentWorktreeDoesNotWriteUnderGitInternals: unable to prepare {$infoDir}\n";
                return 1;
            }
            $excludeFile = $infoDir . '/exclude';
            $sentinel = "# sentinel kept\n";
            file_put_contents($excludeFile, $sentinel);
            clearstatcache(true, $excludeFile);
            $excludeMtime = filemtime($excludeFile);

            $service->prepareAgentWorktree($agent);

            clearstatcache(true, $excludeFile);
            $actual = (string) file_get_contents($excludeFile);
            if ($actual !== $sentinel) {
                echo "FAIL testPrepareAgentWorktreeDoesNotWriteUnderGitInternals: .git/info/exclude content was modified\n";
                return 1;
            }
            if (filemtime($excludeFile) !== $excludeMtime) {
                echo "FAIL testPrepareAgentWorktreeDoesNotWriteUnderGitInternals: .git/info/exclude mtime changed\n";
                return 1;
            }

            // Pre-commit hook must NOT be placed in .git/hooks/ (shared dir, read-only in sandboxed environments).
            $legacyHookPath = $worktree . '/.git/hooks/pre-commit';
            if (is_file($legacyHookPath)) {
                echo "FAIL testPrepareAgentWorktreeDoesNotWriteUnderGitInternals: pre-commit hook was written to .git/hooks/ (shared gitdir)\n";
                return 1;
            }
        } finally {
            $this->cleanupWorktree($worktree);
        }

        echo "OK testPrepareAgentWorktreeDoesNotWriteUnderGitInternals\n";
        return 0;
    }

    private function testPrepareAgentWorktreeCreatesLocalWorkingDirectories(): int
    {
        $agent = 'test-d10-local-dirs-' . $this->uniqueToken();
        $worktreesRoot = $this->projectRoot . '/local/tests/worktree-service';
        $worktree = $worktreesRoot . '/' . $agent;

        try {
            $this->createExistingAgentRepository($worktree);
            $service = $this->createService($worktreesRoot);
            $preparedPath = $service->prepareAgentWorktree($agent);

            if ($preparedPath !== $worktree) {
                echo "FAIL testPrepareAgentWorktreeCreatesLocalWorkingDirectories: unexpected worktree path {$preparedPath}\n";
                return 1;
            }

            $failure = $this->assertLocalWorkingDirectories($worktree);
            if ($failure !== null) {
                echo "FAIL testPrepareAgentWorktreeCreatesLocalWorkingDirectories: {$failure}\n";
                return 1;
            }
        } finally {
            $this->cleanupWorktree($worktree);
        }

        echo "OK testPrepareAgentWorktreeCreatesLocalWorkingDirectories\n";
        return 0;
    }

    private function testPrepareAgentWorktreeLocalWorkingDirectoriesAreIdempotent(): int
    {
        $agent = 'test-d10-local-dirs-' . $this->uniqueToken();
        $worktreesRoot = $this->projectRoot . '/local/tests/worktree-service';
        $worktree = $worktreesRoot . '/' . $agent;

        try {
            $this->createExistingAgentRepository($worktree);
            $service = $this->createService($worktreesRoot);
            $service->prepareAgentWorktree($agent);
            $sentinel = $worktree . '/local/tests/existing-output.txt';
            file_put_contents($sentinel, "kept\n");

            $service->prepareAgentWorktree($agent);

            $failure = $this->assertLocalWorkingDirectories($worktree);
            if ($failure !== null) {
                echo "FAIL testPrepareAgentWorktreeLocalWorkingDirectoriesAreIdempotent: {$failure}\n";
                return 1;
            }
            if (!is_file($sentinel)) {
                echo "FAIL testPrepareAgentWorktreeLocalWorkingDirectoriesAreIdempotent: existing local/tests contents were removed\n";
                return 1;
            }
        } finally {
            $this->cleanupWorktree($worktree);
        }

        echo "OK testPrepareAgentWorktreeLocalWorkingDirectoriesAreIdempotent\n";
        return 0;
    }

    private function testPrepareAgentWorktreeInstallsPreCommitHook(): int
    {
        $agent = 'test-d10-hook-' . $this->uniqueToken();
        $worktreesRoot = $this->projectRoot . '/local/tests/worktree-service';
        $worktree = $worktreesRoot . '/' . $agent;

        try {
            $this->createExistingAgentRepository($worktree);
            $service = $this->createService($worktreesRoot);
            $service->prepareAgentWorktree($agent);

            // Hook must be placed in .githooks/ inside the WA, never in .git/hooks/.
            $hookPath = $worktree . '/.githooks/pre-commit';
            if (!is_file($hookPath)) {
                echo "FAIL testPrepareAgentWorktreeInstallsPreCommitHook: pre-commit hook not found at {$hookPath}\n";
                return 1;
            }
            if (!is_executable($hookPath)) {
                echo "FAIL testPrepareAgentWorktreeInstallsPreCommitHook: pre-commit hook is not executable\n";
                return 1;
            }

            // git must be configured to look in .githooks/ for this WA.
            $hooksPath = $this->captureGitConfig($worktree, 'core.hooksPath');
            if ($hooksPath !== '.githooks') {
                echo "FAIL testPrepareAgentWorktreeInstallsPreCommitHook: core.hooksPath is '{$hooksPath}', expected '.githooks'\n";
                return 1;
            }
        } finally {
            $this->cleanupWorktree($worktree);
        }

        echo "OK testPrepareAgentWorktreeInstallsPreCommitHook\n";
        return 0;
    }

    private function testPrepareAgentWorktreePreCommitHookIsIdempotent(): int
    {
        $agent = 'test-d10-hook-idem-' . $this->uniqueToken();
        $worktreesRoot = $this->projectRoot . '/local/tests/worktree-service';
        $worktree = $worktreesRoot . '/' . $agent;

        try {
            $this->createExistingAgentRepository($worktree);
            $service = $this->createService($worktreesRoot);
            $service->prepareAgentWorktree($agent);
            $service->prepareAgentWorktree($agent);

            $hookPath = $worktree . '/.githooks/pre-commit';
            if (!is_file($hookPath)) {
                echo "FAIL testPrepareAgentWorktreePreCommitHookIsIdempotent: pre-commit hook not found after second prepare\n";
                return 1;
            }
            if (!is_executable($hookPath)) {
                echo "FAIL testPrepareAgentWorktreePreCommitHookIsIdempotent: pre-commit hook is not executable after second prepare\n";
                return 1;
            }

            $hooksPath = $this->captureGitConfig($worktree, 'core.hooksPath');
            if ($hooksPath !== '.githooks') {
                echo "FAIL testPrepareAgentWorktreePreCommitHookIsIdempotent: core.hooksPath is '{$hooksPath}' after second prepare, expected '.githooks'\n";
                return 1;
            }
        } finally {
            $this->cleanupWorktree($worktree);
        }

        echo "OK testPrepareAgentWorktreePreCommitHookIsIdempotent\n";
        return 0;
    }

    private function createService(string $worktreesRoot): BacklogWorktreeService
    {
        $console = new ConsoleClient(
            $this->projectRoot,
            false,
            Application::getInstance(),
            static fn(string $message) => null,
        );

        return new BacklogWorktreeService(
            $this->projectRoot,
            $worktreesRoot,
            false,
            "DATABASE_URL=\"postgresql://app:app@localhost:5432/app\"\n",
            (new \ReflectionClass(BacklogBoardService::class))->newInstanceWithoutConstructor(),
            $console,
            new GitClient(false, $console, new RetryPolicy(0, 0)),
            new ProjectScriptClient($console),
            new FilesystemClient(),
        );
    }

    private function assertLocalWorkingDirectories(string $worktree): ?string
    {
        foreach (['local/tmp', 'local/tests'] as $relativePath) {
            if (!is_dir($worktree . '/' . $relativePath)) {
                return "missing directory {$relativePath}";
            }
            if (!is_file($worktree . '/' . $relativePath . '/.gitkeep')) {
                return "missing keep file {$relativePath}/.gitkeep";
            }
        }

        return null;
    }

    private function cleanupWorktree(string $worktree): void
    {
        $this->removePath($worktree);
    }

    private function createExistingAgentRepository(string $worktree): void
    {
        if (!is_dir($worktree) && !mkdir($worktree, 0o755, true) && !is_dir($worktree)) {
            throw new \RuntimeException("Unable to create worktree fixture: {$worktree}");
        }

        $this->runCommand(sprintf('git init %s', escapeshellarg($this->relativePath($worktree))));

        $localDir = $worktree . '/local';
        if (!is_dir($localDir) && !mkdir($localDir, 0o755, true) && !is_dir($localDir)) {
            throw new \RuntimeException("Unable to create local fixture: {$localDir}");
        }
        file_put_contents($worktree . '/.gitignore', implode("\n", [
            '.env',
            'backend/.env.local',
            'scripts/vendor/',
            'backend/vendor/',
            'frontend/node_modules/',
            'local/*',
            '!local/.gitignore',
            '.githooks/',
            '',
        ]));
        file_put_contents($localDir . '/.gitignore', "*\n!.gitignore\n");

        $this->runCommand(sprintf(
            'git -C %s config user.email test@example.local',
            escapeshellarg($this->relativePath($worktree)),
        ));
        $this->runCommand(sprintf(
            'git -C %s config user.name Test',
            escapeshellarg($this->relativePath($worktree)),
        ));
        $this->runCommand(sprintf(
            'git -C %s add .gitignore local/.gitignore',
            escapeshellarg($this->relativePath($worktree)),
        ));
        $this->runCommand(sprintf(
            'git -C %s commit -m init',
            escapeshellarg($this->relativePath($worktree)),
        ));
    }

    private function captureGitConfig(string $worktree, string $key): string
    {
        $output = [];
        $code = 0;
        exec(sprintf(
            'git -C %s config --get %s 2>&1',
            escapeshellarg($this->relativePath($worktree)),
            escapeshellarg($key),
        ), $output, $code);

        return trim(implode("\n", $output));
    }

    private function createReviewScriptFixture(string $worktree, string $output, int $exitCode): void
    {
        $scriptDir = $worktree . '/scripts';
        if (!is_dir($scriptDir) && !mkdir($scriptDir, 0o755, true) && !is_dir($scriptDir)) {
            throw new \RuntimeException("Unable to create script fixture: {$scriptDir}");
        }

        file_put_contents($scriptDir . '/review.php', sprintf(
            "<?php\necho %s;\nexit(%d);\n",
            var_export($output, true),
            $exitCode,
        ));
    }

    private function runCommand(string $command): void
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

    private function removePath(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
                continue;
            }
            @unlink($item->getPathname());
        }
        @rmdir($path);
    }

    private function relativePath(string $path): string
    {
        $prefix = rtrim($this->projectRoot, '/') . '/';
        if (str_starts_with($path, $prefix)) {
            return substr($path, strlen($prefix));
        }

        return $path;
    }

    private function uniqueToken(): string
    {
        return str_replace('.', '', uniqid('', true));
    }
}
