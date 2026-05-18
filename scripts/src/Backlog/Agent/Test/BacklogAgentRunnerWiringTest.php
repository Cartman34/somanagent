<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Test;

use SoManAgent\Script\Backlog\Agent\Command\AgentStartCommand;
use SoManAgent\Script\Backlog\Agent\Runner\BacklogAgentRunner;
use SoManAgent\Script\Backlog\Service\EntryRebaseService;

/**
 * Runner-level wiring tests for {@see BacklogAgentRunner}.
 *
 * Verifies that every mandatory dependency of {@see AgentStartCommand} is
 * properly injected by the runner's commands() factory. This is the class of
 * test that catches missing-injection regressions (such as a missing
 * EntryRebaseService) that PHP cannot catch at compile time when a dependency
 * is declared nullable with a default of null.
 */
final class BacklogAgentRunnerWiringTest
{
    /**
     * Runs all test cases and returns the total number of failures.
     */
    public function run(): int
    {
        $failed = 0;

        $failed += $this->testStartCommandHasEntryRebaseServiceInjected();

        return $failed;
    }

    private function testStartCommandHasEntryRebaseServiceInjected(): int
    {
        $runner = new BacklogAgentRunner();

        // Access the private commands() method via reflection to get the start command
        // without invoking the full run() lifecycle (which requires live board state).
        $commandsMethod = new \ReflectionMethod($runner, 'commands');
        $commandsMethod->setAccessible(true);

        /** @var array<string, \SoManAgent\Script\Backlog\Agent\Command\AbstractAgentCommand> $commands */
        $commands = $commandsMethod->invoke($runner);

        $startCommand = $commands['start'] ?? null;
        if (!$startCommand instanceof AgentStartCommand) {
            echo "FAIL testStartCommandHasEntryRebaseServiceInjected: start command is not an AgentStartCommand\n";
            return 1;
        }

        $prop = new \ReflectionProperty($startCommand, 'entryRebaseService');
        $prop->setAccessible(true);
        $value = $prop->getValue($startCommand);

        if (!$value instanceof EntryRebaseService) {
            echo "FAIL testStartCommandHasEntryRebaseServiceInjected: entryRebaseService is "
                . (is_object($value) ? get_class($value) : gettype($value))
                . ", expected EntryRebaseService instance\n";
            return 1;
        }

        echo "OK testStartCommandHasEntryRebaseServiceInjected\n";
        return 0;
    }
}
