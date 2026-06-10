<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Runner\Test;

use Sowapps\SoManAgent\Script\Runner\GenerateMigrationService;

/**
 * Unit tests for GenerateMigrationService pure helpers.
 */
final class GenerateMigrationServiceTest
{
    /**
     * Runs all test cases and returns the total number of failures.
     * @api
     */
    public function run(): int
    {
        $failed = 0;

        $failed += $this->testBuildTempDbNameNormalizesAgentCode();

        return $failed;
    }

    private function testBuildTempDbNameNormalizesAgentCode(): int
    {
        $cases = [
            'd04' => 'd04_migrate_gen',
            'D-04' => 'd_04_migrate_gen',
            'reviewer.01' => 'reviewer_01_migrate_gen',
        ];

        foreach ($cases as $agentCode => $expected) {
            $actual = GenerateMigrationService::buildTempDbName($agentCode);
            if ($actual !== $expected) {
                echo "FAIL testBuildTempDbNameNormalizesAgentCode: {$agentCode} => {$actual}, want {$expected}\n";
                return 1;
            }
        }

        echo "OK testBuildTempDbNameNormalizesAgentCode\n";
        return 0;
    }
}
