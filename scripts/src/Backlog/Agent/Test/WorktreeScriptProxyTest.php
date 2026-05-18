<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Test;

use SoManAgent\Script\WorktreeScriptProxy;

/**
 * Unit tests for WorktreeScriptProxy.
 *
 * Covers the standardized error message produced when the equivalent script
 * is missing from the main worktree. The end-to-end exit path is not tested
 * here because the static run() method calls exit() and is verified manually.
 */
final class WorktreeScriptProxyTest
{
    /**
     * Runs all test cases and returns the total number of failures.
     */
    public function run(): int
    {
        $failed = 0;

        $failed += $this->testMissingScriptErrorNamesScriptAndMainPath();
        $failed += $this->testMissingScriptErrorFormatStable();
        $failed += $this->testMissingScriptErrorIsSharedAcrossScripts();
        $failed += $this->testDetectClearsGitDirBeforeGitCommands();

        return $failed;
    }

    private function testMissingScriptErrorNamesScriptAndMainPath(): int
    {
        $message = WorktreeScriptProxy::formatMissingScriptError(
            'scripts/backlog-agent.php',
            '/wp/scripts/backlog-agent.php',
        );

        if (!str_contains($message, 'scripts/backlog-agent.php')) {
            echo "FAIL testMissingScriptErrorNamesScriptAndMainPath: missing relative path: {$message}\n";
            return 1;
        }
        if (!str_contains($message, '/wp/scripts/backlog-agent.php')) {
            echo "FAIL testMissingScriptErrorNamesScriptAndMainPath: missing main path: {$message}\n";
            return 1;
        }
        echo "OK testMissingScriptErrorNamesScriptAndMainPath\n";
        return 0;
    }

    private function testMissingScriptErrorFormatStable(): int
    {
        $message = WorktreeScriptProxy::formatMissingScriptError(
            'scripts/foo.php',
            '/wp/scripts/foo.php',
        );
        $expected = '❌ Proxy error: requested script `scripts/foo.php` is missing from main worktree at `/wp/scripts/foo.php`.';

        if ($message !== $expected) {
            echo "FAIL testMissingScriptErrorFormatStable: got: {$message}\n";
            return 1;
        }
        echo "OK testMissingScriptErrorFormatStable\n";
        return 0;
    }

    /**
     * Regression: git sets GIT_DIR when running hooks; detect() must clear it before calling git so
     * that `git -C <subdir> rev-parse --show-toplevel` returns the worktree root, not <subdir>.
     *
     * The test is only meaningful inside a linked worktree (agent WA). It is skipped otherwise.
     */
    private function testDetectClearsGitDirBeforeGitCommands(): int
    {
        $output = [];
        $code = 0;
        exec('git rev-parse --git-dir 2>/dev/null', $output, $code);
        $gitDir = $code === 0 ? trim(implode('', $output)) : '';

        $commonOutput = [];
        exec('git rev-parse --git-common-dir 2>/dev/null', $commonOutput);
        $gitCommonDir = trim(implode('', $commonOutput));

        if ($gitDir === '' || $gitDir === $gitCommonDir) {
            echo "SKIP testDetectClearsGitDirBeforeGitCommands: not running inside a linked worktree\n";
            return 0;
        }

        $savedGitDir = getenv('GIT_DIR');
        putenv('GIT_DIR=' . $gitDir);

        try {
            $instance = WorktreeScriptProxy::detect('scripts/backlog.php');

            if (!$instance->isLinkedWorktree()) {
                echo "FAIL testDetectClearsGitDirBeforeGitCommands: expected linked worktree detection\n";
                return 1;
            }

            if ($instance->getRelativePath() !== 'scripts/backlog.php') {
                echo "FAIL testDetectClearsGitDirBeforeGitCommands: expected relativePath=scripts/backlog.php, got {$instance->getRelativePath()}\n";
                return 1;
            }
        } catch (\RuntimeException $e) {
            echo "FAIL testDetectClearsGitDirBeforeGitCommands: unexpected exception: {$e->getMessage()}\n";
            return 1;
        } finally {
            if ($savedGitDir === false) {
                putenv('GIT_DIR');
            } else {
                putenv('GIT_DIR=' . $savedGitDir);
            }
        }

        echo "OK testDetectClearsGitDirBeforeGitCommands\n";
        return 0;
    }

    private function testMissingScriptErrorIsSharedAcrossScripts(): int
    {
        $messageAgent = WorktreeScriptProxy::formatMissingScriptError(
            'scripts/backlog-agent.php',
            '/wp/scripts/backlog-agent.php',
        );
        $messageBacklog = WorktreeScriptProxy::formatMissingScriptError(
            'scripts/backlog.php',
            '/wp/scripts/backlog.php',
        );

        // Same structure regardless of which script was requested.
        $structureAgent = preg_replace('/`[^`]+`/', '`X`', $messageAgent);
        $structureBacklog = preg_replace('/`[^`]+`/', '`X`', $messageBacklog);

        if ($structureAgent !== $structureBacklog) {
            echo "FAIL testMissingScriptErrorIsSharedAcrossScripts: format differs between scripts\n";
            echo "  agent:   {$messageAgent}\n";
            echo "  backlog: {$messageBacklog}\n";
            return 1;
        }
        echo "OK testMissingScriptErrorIsSharedAcrossScripts\n";
        return 0;
    }
}
