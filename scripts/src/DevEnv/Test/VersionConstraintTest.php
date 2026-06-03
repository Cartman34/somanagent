<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\DevEnv\Test;

use Sowapps\SoManAgent\Script\DevEnv\VersionConstraint;

/**
 * Unit tests for VersionConstraint.
 */
final class VersionConstraintTest
{
    /**
     * Runs all test cases and returns the total number of failures.
     * @api
     */
    public function run(): int
    {
        $failed = 0;

        $failed += $this->testGteOperator();
        $failed += $this->testNormalizeStripsUbuntuSuffix();
        $failed += $this->testNormalizeStripsDebianEpoch();
        $failed += $this->testHighestReturnsBestSatisfying();
        $failed += $this->testHighestReturnsNullWhenNoneSatisfy();
        $failed += $this->testCaretConstraint();
        $failed += $this->testTildeConstraint();
        $failed += $this->testExactMatch();

        return $failed;
    }

    private function testGteOperator(): int
    {
        $vc = new VersionConstraint();

        $cases = [
            ['8.4.3', '>=8.4', true],
            ['8.3.0', '>=8.4', false],
            ['8.4.0', '>=8.4', true],
            ['24.0.7', '>=24', true],
            ['23.9.9', '>=24', false],
            ['2.0.0', '>=2', true],
            ['1.9.9', '>=2', false],
        ];

        foreach ($cases as [$version, $constraint, $expected]) {
            $result = $vc->satisfies($version, $constraint);
            if ($result !== $expected) {
                $exp = $expected ? 'true' : 'false';
                $got = $result ? 'true' : 'false';
                echo "FAIL testGteOperator: satisfies({$version}, {$constraint}) = {$got}, want {$exp}\n";
                return 1;
            }
        }

        echo "OK testGteOperator\n";
        return 0;
    }

    private function testNormalizeStripsUbuntuSuffix(): int
    {
        $vc = new VersionConstraint();

        if (!$vc->satisfies('8.4.3-1ubuntu1.0~22.04.1', '>=8.4')) {
            echo "FAIL testNormalizeStripsUbuntuSuffix: 8.4.3-1ubuntu1 should satisfy >=8.4\n";
            return 1;
        }
        if ($vc->satisfies('8.3.9-1ubuntu1.0', '>=8.4')) {
            echo "FAIL testNormalizeStripsUbuntuSuffix: 8.3.9-1ubuntu1 should not satisfy >=8.4\n";
            return 1;
        }

        echo "OK testNormalizeStripsUbuntuSuffix\n";
        return 0;
    }

    private function testNormalizeStripsDebianEpoch(): int
    {
        $vc = new VersionConstraint();

        // epoch must not be treated as major version number
        if (!$vc->satisfies('1:2.43.0-1ubuntu7.3', '>=2.30')) {
            echo "FAIL testNormalizeStripsDebianEpoch: 1:2.43.0-1ubuntu7.3 should satisfy >=2.30\n";
            return 1;
        }
        if ($vc->satisfies('1:2.43.0-1ubuntu7.3', '>=3')) {
            echo "FAIL testNormalizeStripsDebianEpoch: 1:2.43.0-1ubuntu7.3 should not satisfy >=3\n";
            return 1;
        }
        if (!$vc->satisfies('5:24.0.7-1~ubuntu.22.04~jammy', '>=24')) {
            echo "FAIL testNormalizeStripsDebianEpoch: 5:24.0.7-1~ubuntu.22.04~jammy should satisfy >=24\n";
            return 1;
        }
        if ($vc->satisfies('5:24.0.7-1~ubuntu.22.04~jammy', '>=25')) {
            echo "FAIL testNormalizeStripsDebianEpoch: 5:24.0.7-1~ubuntu.22.04~jammy should not satisfy >=25\n";
            return 1;
        }

        echo "OK testNormalizeStripsDebianEpoch\n";
        return 0;
    }

    private function testHighestReturnsBestSatisfying(): int
    {
        $vc = new VersionConstraint();

        $versions = ['1.0.0', '1.2.3', '2.0.0', '0.9.5'];
        $result = $vc->highest($versions, '>=1.0');

        if ($result !== '2.0.0') {
            echo "FAIL testHighestReturnsBestSatisfying: expected 2.0.0, got {$result}\n";
            return 1;
        }

        echo "OK testHighestReturnsBestSatisfying\n";
        return 0;
    }

    private function testHighestReturnsNullWhenNoneSatisfy(): int
    {
        $vc = new VersionConstraint();

        $result = $vc->highest(['1.0.0', '1.5.0'], '>=2.0');

        if ($result !== null) {
            echo "FAIL testHighestReturnsNullWhenNoneSatisfy: expected null, got {$result}\n";
            return 1;
        }

        echo "OK testHighestReturnsNullWhenNoneSatisfy\n";
        return 0;
    }

    private function testCaretConstraint(): int
    {
        $vc = new VersionConstraint();

        if (!$vc->satisfies('1.5.0', '^1.0.0')) {
            echo "FAIL testCaretConstraint: 1.5.0 should satisfy ^1.0.0\n";
            return 1;
        }
        if ($vc->satisfies('2.0.0', '^1.0.0')) {
            echo "FAIL testCaretConstraint: 2.0.0 should not satisfy ^1.0.0\n";
            return 1;
        }

        echo "OK testCaretConstraint\n";
        return 0;
    }

    private function testTildeConstraint(): int
    {
        $vc = new VersionConstraint();

        if (!$vc->satisfies('1.2.9', '~1.2.3')) {
            echo "FAIL testTildeConstraint: 1.2.9 should satisfy ~1.2.3\n";
            return 1;
        }
        if ($vc->satisfies('1.3.0', '~1.2.3')) {
            echo "FAIL testTildeConstraint: 1.3.0 should not satisfy ~1.2.3\n";
            return 1;
        }

        echo "OK testTildeConstraint\n";
        return 0;
    }

    private function testExactMatch(): int
    {
        $vc = new VersionConstraint();

        if (!$vc->satisfies('1.2.3', '=1.2.3')) {
            echo "FAIL testExactMatch: 1.2.3 should satisfy =1.2.3\n";
            return 1;
        }
        if ($vc->satisfies('1.2.4', '=1.2.3')) {
            echo "FAIL testExactMatch: 1.2.4 should not satisfy =1.2.3\n";
            return 1;
        }

        echo "OK testExactMatch\n";
        return 0;
    }
}
