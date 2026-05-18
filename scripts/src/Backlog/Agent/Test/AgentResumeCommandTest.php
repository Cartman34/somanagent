<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Test;

use SoManAgent\Script\Backlog\Agent\Command\AgentResumeCommand;

/**
 * Tests that the removed resume command redirects callers to start.
 */
final class AgentResumeCommandTest
{
    /**
     * Runs all test cases and returns the total number of failures.
     */
    public function run(): int
    {
        $failed = 0;

        $failed += $this->testResumeThrowsRedirectError();

        return $failed;
    }

    private function testResumeThrowsRedirectError(): int
    {
        $cmd = new AgentResumeCommand();

        $threw = false;
        try {
            $cmd->handle([], []);
        } catch (\RuntimeException $e) {
            $threw = str_contains($e->getMessage(), "'resume' command has been removed")
                && str_contains($e->getMessage(), 'start --code=<code>');
        }

        if (!$threw) {
            echo "FAIL testResumeThrowsRedirectError: expected redirect error mentioning 'resume' removal and 'start --code'\n";
            return 1;
        }

        echo "OK testResumeThrowsRedirectError\n";
        return 0;
    }
}
