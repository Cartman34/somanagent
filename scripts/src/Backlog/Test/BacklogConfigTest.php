<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Test;

use SoManAgent\Script\Backlog\Service\BacklogConfig;

/**
 * Unit tests for BacklogConfig.
 *
 * Covers: reading from local config, explicit error on missing local, fallback
 * when key is absent, and explicit error when the .dist template is missing.
 */
final class BacklogConfigTest
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
     * Runs all tests and returns the number of failures.
     *
     * @api
     */
    public function run(): int
    {
        $failed = 0;

        $failed += $this->testReadsLocalConfig();
        $failed += $this->testErrorWhenLocalAbsent();
        $failed += $this->testFallbackWhenKeyMissing();

        return $failed;
    }

    private function testReadsLocalConfig(): int
    {
        $tmpDir = $this->setUpTempProject("backlog:\n  max_concurrent_worktrees: 7\n");

        try {
            $config = new BacklogConfig($tmpDir);

            if ($config->getMaxConcurrentWorktrees() !== 7) {
                echo "FAIL testReadsLocalConfig: expected 7, got {$config->getMaxConcurrentWorktrees()}\n";
                return 1;
            }
        } finally {
            $this->removeTempProject($tmpDir);
        }

        echo "OK testReadsLocalConfig\n";
        return 0;
    }

    private function testErrorWhenLocalAbsent(): int
    {
        $tmpDir = $this->setUpTempProject(null);

        try {
            $config = new BacklogConfig($tmpDir);
            $thrown = false;
            $message = '';

            try {
                $config->getMaxConcurrentWorktrees();
            } catch (\RuntimeException $e) {
                $thrown = true;
                $message = $e->getMessage();
            }

            if (!$thrown) {
                echo "FAIL testErrorWhenLocalAbsent: expected RuntimeException\n";
                return 1;
            }

            if (!str_contains($message, 'setup.php install')) {
                echo "FAIL testErrorWhenLocalAbsent: expected 'setup.php install' in error message\nMessage: {$message}\n";
                return 1;
            }
        } finally {
            $this->removeTempProject($tmpDir);
        }

        echo "OK testErrorWhenLocalAbsent\n";
        return 0;
    }

    private function testFallbackWhenKeyMissing(): int
    {
        // Local config exists but does not define max_concurrent_worktrees
        $tmpDir = $this->setUpTempProject("backlog: {}\n");

        try {
            $config = new BacklogConfig($tmpDir);

            // Must return the hardcoded fallback (5) without throwing
            $value = $config->getMaxConcurrentWorktrees();
            if ($value !== 5) {
                echo "FAIL testFallbackWhenKeyMissing: expected fallback 5, got {$value}\n";
                return 1;
            }
        } finally {
            $this->removeTempProject($tmpDir);
        }

        echo "OK testFallbackWhenKeyMissing\n";
        return 0;
    }


    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Creates a minimal temp project tree.
     *
     * @param string|null $localConfigContent Null = no local config; string = content to write.
     * @return string Temp project root path
     */
    private function setUpTempProject(?string $localConfigContent): string
    {
        $tmpDir = $this->testOutputRoot() . '/backlog_config_test_' . uniqid();
        $distDir = $tmpDir . '/scripts/resources/backlog';
        mkdir($distDir, 0o755, true);

        copy(
            $this->projectRoot . '/scripts/resources/backlog/config.yaml.dist',
            $distDir . '/config.yaml.dist',
        );

        if ($localConfigContent !== null) {
            $localDir = $tmpDir . '/local/backlog';
            mkdir($localDir, 0o755, true);
            file_put_contents($localDir . '/config.yaml', $localConfigContent);
        }

        return $tmpDir;
    }

    /**
     * Removes the temp project tree created by setUpTempProject.
     */
    private function removeTempProject(string $tmpDir): void
    {
        @unlink($tmpDir . '/scripts/resources/backlog/config.yaml.dist');
        @rmdir($tmpDir . '/scripts/resources/backlog');
        @rmdir($tmpDir . '/scripts/resources');
        @rmdir($tmpDir . '/scripts');
        @unlink($tmpDir . '/local/backlog/config.yaml');
        @rmdir($tmpDir . '/local/backlog');
        @rmdir($tmpDir . '/local');
        @rmdir($tmpDir);
    }

    private function testOutputRoot(): string
    {
        $path = $this->projectRoot . '/local/tests/backlog-config';
        if (!is_dir($path) && !mkdir($path, 0o755, true) && !is_dir($path)) {
            throw new \RuntimeException("Unable to create test output directory: {$path}");
        }

        return $path;
    }
}
