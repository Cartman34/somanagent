<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Agent\Test;

use Sowapps\SoManAgent\Script\Backlog\Agent\Service\AgentCliOptionValidator;

/**
 * Unit tests for AgentCliOptionValidator.
 */
final class AgentCliOptionValidatorTest
{
    /**
     * Runs all test cases and returns the total number of failures.
     */
    public function run(): int
    {
        $failed = 0;

        $failed += $this->testAcceptsDeclaredOption();
        $failed += $this->testAcceptsRunnerHelp();
        $failed += $this->testAcceptsRunnerForceCurrentWorktree();
        $failed += $this->testRejectsUnknownOption();
        $failed += $this->testRejectsAsTypo();
        $failed += $this->testRejectsMultipleUnknownOptionsSortedAndPrefixed();
        $failed += $this->testEqualsFormAndSpaceFormShareKey();
        $failed += $this->testGlobalOptionsAcceptHelp();
        $failed += $this->testGlobalOptionsRejectUnknown();

        return $failed;
    }

    private function testAcceptsDeclaredOption(): int
    {
        $validator = new AgentCliOptionValidator();
        try {
            $validator->assertCommandOptionsAccepted(
                'start',
                [
                    ['name' => '--developer', 'description' => ''],
                    ['name' => '--code=<code>', 'description' => ''],
                ],
                ['developer' => true, 'code' => 'd04'],
            );
        } catch (\RuntimeException $e) {
            echo "FAIL testAcceptsDeclaredOption: unexpected error: {$e->getMessage()}\n";
            return 1;
        }
        echo "OK testAcceptsDeclaredOption\n";
        return 0;
    }

    private function testAcceptsRunnerHelp(): int
    {
        $validator = new AgentCliOptionValidator();
        try {
            $validator->assertCommandOptionsAccepted(
                'start',
                [['name' => '--developer', 'description' => '']],
                ['help' => true],
            );
        } catch (\RuntimeException $e) {
            echo "FAIL testAcceptsRunnerHelp: unexpected error: {$e->getMessage()}\n";
            return 1;
        }
        echo "OK testAcceptsRunnerHelp\n";
        return 0;
    }

    private function testAcceptsRunnerForceCurrentWorktree(): int
    {
        $validator = new AgentCliOptionValidator();
        try {
            $validator->assertCommandOptionsAccepted(
                'list',
                [],
                ['force-current-worktree' => true],
            );
        } catch (\RuntimeException $e) {
            echo "FAIL testAcceptsRunnerForceCurrentWorktree: unexpected error: {$e->getMessage()}\n";
            return 1;
        }
        echo "OK testAcceptsRunnerForceCurrentWorktree\n";
        return 0;
    }

    private function testRejectsUnknownOption(): int
    {
        $validator = new AgentCliOptionValidator();
        try {
            $validator->assertCommandOptionsAccepted(
                'start',
                [['name' => '--developer', 'description' => '']],
                ['unknown' => true],
            );
            echo "FAIL testRejectsUnknownOption: expected RuntimeException\n";
            return 1;
        } catch (\RuntimeException $e) {
            if (!str_contains($e->getMessage(), 'Unknown option(s) for command `start`: --unknown')) {
                echo "FAIL testRejectsUnknownOption: unexpected message: {$e->getMessage()}\n";
                return 1;
            }
        }
        echo "OK testRejectsUnknownOption\n";
        return 0;
    }

    private function testRejectsAsTypo(): int
    {
        $validator = new AgentCliOptionValidator();
        try {
            $validator->assertCommandOptionsAccepted(
                'status',
                [['name' => '--code=<code>', 'description' => '']],
                ['as' => 'd04'],
            );
            echo "FAIL testRejectsAsTypo: expected RuntimeException\n";
            return 1;
        } catch (\RuntimeException $e) {
            if (!str_contains($e->getMessage(), 'Unknown option(s) for command `status`: --as')) {
                echo "FAIL testRejectsAsTypo: unexpected message: {$e->getMessage()}\n";
                return 1;
            }
        }
        echo "OK testRejectsAsTypo\n";
        return 0;
    }

    private function testRejectsMultipleUnknownOptionsSortedAndPrefixed(): int
    {
        $validator = new AgentCliOptionValidator();
        try {
            $validator->assertCommandOptionsAccepted(
                'start',
                [['name' => '--developer', 'description' => '']],
                ['zfoo' => true, 'as' => 'd04'],
            );
            echo "FAIL testRejectsMultipleUnknownOptionsSortedAndPrefixed: expected RuntimeException\n";
            return 1;
        } catch (\RuntimeException $e) {
            if (!str_contains($e->getMessage(), 'Unknown option(s) for command `start`: --as, --zfoo')) {
                echo "FAIL testRejectsMultipleUnknownOptionsSortedAndPrefixed: unexpected message: {$e->getMessage()}\n";
                return 1;
            }
        }
        echo "OK testRejectsMultipleUnknownOptionsSortedAndPrefixed\n";
        return 0;
    }

    private function testEqualsFormAndSpaceFormShareKey(): int
    {
        $validator = new AgentCliOptionValidator();
        $declared = [['name' => '--code=<code>', 'description' => '']];

        try {
            $validator->assertCommandOptionsAccepted('status', $declared, ['code' => 'd04']);
        } catch (\RuntimeException $e) {
            echo "FAIL testEqualsFormAndSpaceFormShareKey: rejected --code=d04 form: {$e->getMessage()}\n";
            return 1;
        }

        try {
            $validator->assertCommandOptionsAccepted('status', $declared, ['code' => true]);
        } catch (\RuntimeException $e) {
            echo "FAIL testEqualsFormAndSpaceFormShareKey: rejected --code true form: {$e->getMessage()}\n";
            return 1;
        }

        echo "OK testEqualsFormAndSpaceFormShareKey\n";
        return 0;
    }

    private function testGlobalOptionsAcceptHelp(): int
    {
        $validator = new AgentCliOptionValidator();
        try {
            $validator->assertGlobalOptionsAccepted(['help' => true]);
        } catch (\RuntimeException $e) {
            echo "FAIL testGlobalOptionsAcceptHelp: unexpected error: {$e->getMessage()}\n";
            return 1;
        }
        echo "OK testGlobalOptionsAcceptHelp\n";
        return 0;
    }

    private function testGlobalOptionsRejectUnknown(): int
    {
        $validator = new AgentCliOptionValidator();
        try {
            $validator->assertGlobalOptionsAccepted(['as' => 'd04']);
            echo "FAIL testGlobalOptionsRejectUnknown: expected RuntimeException\n";
            return 1;
        } catch (\RuntimeException $e) {
            if (!str_contains($e->getMessage(), 'Unknown global option(s): --as')) {
                echo "FAIL testGlobalOptionsRejectUnknown: unexpected message: {$e->getMessage()}\n";
                return 1;
            }
        }
        echo "OK testGlobalOptionsRejectUnknown\n";
        return 0;
    }
}
