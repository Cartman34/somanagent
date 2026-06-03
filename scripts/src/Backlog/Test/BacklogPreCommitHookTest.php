<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Test;

/**
 * Integration tests for the managed worktree pre-commit hook.
 *
 * Covers: correct script path in the hook source, commit allowed in development
 * stage, and commit blocked in non-development stages.
 */
final class BacklogPreCommitHookTest
{
    private string $projectRoot;
    private string $hookSourcePath;

    /**
     * Resolves paths from the project root.
     */
    public function __construct()
    {
        $this->projectRoot = dirname(__DIR__, 4);
        $this->hookSourcePath = $this->projectRoot . '/scripts/githooks/pre-commit';
    }

    /**
     * Runs all hook tests and returns the number of failures.
     */
    public function run(): int
    {
        $failed = 0;

        $failed += $this->testHookSourceReferencesScriptsBacklogPhp();
        $failed += $this->testHookAllowsCommitInDevelopmentStage();
        $failed += $this->testHookBlocksCommitInNonDevelopmentStage();

        return $failed;
    }

    /**
     * Asserts the versioned hook source invokes scripts/backlog.php, not backlog.php at repo root.
     */
    private function testHookSourceReferencesScriptsBacklogPhp(): int
    {
        $content = file_get_contents($this->hookSourcePath);
        if ($content === false) {
            echo "FAIL testHookSourceReferencesScriptsBacklogPhp: cannot read hook source at {$this->hookSourcePath}\n";
            return 1;
        }

        if (!str_contains($content, '$WA_ROOT/scripts/backlog.php')) {
            echo "FAIL testHookSourceReferencesScriptsBacklogPhp: hook source does not invoke \$WA_ROOT/scripts/backlog.php\n";
            return 1;
        }

        echo "OK testHookSourceReferencesScriptsBacklogPhp\n";
        return 0;
    }

    /**
     * Verifies that the hook exits 0 when precommit-check succeeds (development stage).
     */
    private function testHookAllowsCommitInDevelopmentStage(): int
    {
        return $this->runHookWithStub(
            'testHookAllowsCommitInDevelopmentStage',
            0,
            '',
            0,
        );
    }

    /**
     * Verifies that the hook exits 1 and prints the blocked message when precommit-check fails.
     */
    private function testHookBlocksCommitInNonDevelopmentStage(): int
    {
        return $this->runHookWithStub(
            'testHookBlocksCommitInNonDevelopmentStage',
            1,
            "❌ Commit blocked: entry is in stage 'review'.\n",
            1,
            '❌ Commit blocked',
        );
    }

    /**
     * Creates a temporary WA-like git repo, installs the hook, injects a stub backlog.php,
     * runs the hook script, and asserts the expected exit code and optional output fragment.
     *
     * @param string      $testName          Test identifier for failure messages
     * @param int         $stubExitCode      Exit code the stub backlog.php returns for precommit-check
     * @param string      $stubStderr        Text the stub writes to stderr when blocking
     * @param int         $expectedExitCode  Expected hook exit code
     * @param string|null $expectedOutput    Fragment that must appear in captured hook output, or null
     */
    private function runHookWithStub(
        string $testName,
        int $stubExitCode,
        string $stubStderr,
        int $expectedExitCode,
        ?string $expectedOutput = null,
    ): int {
        $agent = 'test-pre-commit-' . bin2hex(random_bytes(4));
        $waParent = $this->projectRoot . '/local/tests/pre-commit-hook';
        $agentWorktreesDir = $waParent . '/.agent-worktrees';
        $waDir = $agentWorktreesDir . '/' . $agent;

        try {
            if (!is_dir($waDir) && !mkdir($waDir, 0o755, true) && !is_dir($waDir)) {
                echo "FAIL $testName: cannot create WA directory\n";
                return 1;
            }

            $initOutput = [];
            $initCode = 0;
            exec(sprintf('git init %s 2>&1', escapeshellarg($waDir)), $initOutput, $initCode);
            if ($initCode !== 0) {
                echo "FAIL $testName: git init failed: " . implode("\n", $initOutput) . "\n";
                return 1;
            }

            $hooksDir = $waDir . '/.githooks';
            if (!is_dir($hooksDir)) {
                mkdir($hooksDir, 0o755);
            }
            $hookPath = $hooksDir . '/pre-commit';
            if (!copy($this->hookSourcePath, $hookPath)) {
                echo "FAIL $testName: cannot install hook\n";
                return 1;
            }
            chmod($hookPath, 0o755);

            $scriptsDir = $waDir . '/scripts';
            if (!is_dir($scriptsDir)) {
                mkdir($scriptsDir, 0o755);
            }
            $stub = sprintf(
                "<?php\nif (isset(\$argv[1]) && \$argv[1] === 'precommit-check') {\n    if (%d !== 0) { fwrite(STDERR, %s); }\n    exit(%d);\n}\nexit(0);\n",
                $stubExitCode,
                var_export($stubStderr, true),
                $stubExitCode,
            );
            file_put_contents($waDir . '/scripts/backlog.php', $stub);

            $cmd = sprintf(
                'cd %s && SOMANAGER_AGENT=%s SOMANAGER_ROLE=developer bash .githooks/pre-commit 2>&1',
                escapeshellarg($waDir),
                escapeshellarg($agent),
            );
            $hookOutput = [];
            $exitCode = 0;
            exec($cmd, $hookOutput, $exitCode);
            $outputStr = implode("\n", $hookOutput);

            if ($exitCode !== $expectedExitCode) {
                echo "FAIL $testName: expected exit code $expectedExitCode, got $exitCode. Output: $outputStr\n";
                return 1;
            }

            if ($expectedOutput !== null && !str_contains($outputStr, $expectedOutput)) {
                echo "FAIL $testName: expected output to contain '$expectedOutput', got: $outputStr\n";
                return 1;
            }
        } finally {
            $this->removePath($waDir);
            if (is_dir($agentWorktreesDir) && count((array) scandir($agentWorktreesDir)) === 2) {
                rmdir($agentWorktreesDir);
            }
            if (is_dir($waParent) && count((array) scandir($waParent)) === 2) {
                rmdir($waParent);
            }
        }

        echo "OK $testName\n";
        return 0;
    }

    /**
     * Recursively removes a path (file or directory).
     */
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
}
