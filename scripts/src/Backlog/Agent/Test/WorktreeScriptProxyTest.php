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
     * @api
     */
    public function run(): int
    {
        $failed = 0;

        $failed += $this->testMissingScriptErrorNamesScriptAndMainPath();
        $failed += $this->testMissingScriptErrorFormatStable();
        $failed += $this->testMissingScriptErrorIsSharedAcrossScripts();

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
