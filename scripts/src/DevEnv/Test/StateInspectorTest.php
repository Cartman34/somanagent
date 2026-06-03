<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\DevEnv\Test;

use Sowapps\SoManAgent\Script\DevEnv\Model\Dependency;
use Sowapps\SoManAgent\Script\DevEnv\StateInspector;
use Sowapps\SoManAgent\Script\DevEnv\Test\FakeCommandRunner;
/**
 * Unit tests for StateInspector.
 */
final class StateInspectorTest
{
    private const INSTALLER_NPM_GLOBAL = 'npm-global';
    private const CMD_DPKG_QUERY       = 'dpkg-query';

    /**
     * Runs all test cases and returns the total number of failures.
     * @api
     */
    public function run(): int
    {
        $failed = 0;

        $failed += $this->testDetectsAptPackage();
        $failed += $this->testReturnsNullWhenAptMissing();
        $failed += $this->testDetectsNpmPackage();
        $failed += $this->testReturnsNullWhenNpmMissing();
        $failed += $this->testDetectsGitHubReleaseBinary();
        $failed += $this->testCachePreventsDoubleQuery();
        $failed += $this->testClearCacheAllowsRedetection();

        return $failed;
    }

    private function testDetectsAptPackage(): int
    {
        $runner = new FakeCommandRunner();
        $runner->setOutput("dpkg-query -W -f='\${Version}' 'git'", '2.39.2-0ubuntu0.22.04.1');

        $dep = new Dependency('git', 'system', '>=2.30', 'apt', 'git', ['default']);
        $inspector = new StateInspector($runner);

        $version = $inspector->getInstalledVersion($dep);
        if ($version !== '2.39.2-0ubuntu0.22.04.1') {
            echo "FAIL testDetectsAptPackage: expected 2.39.2-0ubuntu0.22.04.1, got {$version}\n";
            return 1;
        }

        echo "OK testDetectsAptPackage\n";
        return 0;
    }

    private function testReturnsNullWhenAptMissing(): int
    {
        $runner = new FakeCommandRunner();
        // No output registered → runner returns null

        $dep = new Dependency('git', 'system', '>=2.30', 'apt', 'git', ['default']);
        $inspector = new StateInspector($runner);

        $version = $inspector->getInstalledVersion($dep);
        if ($version !== null) {
            echo "FAIL testReturnsNullWhenAptMissing: expected null, got {$version}\n";
            return 1;
        }

        echo "OK testReturnsNullWhenAptMissing\n";
        return 0;
    }

    private function testDetectsNpmPackage(): int
    {
        $runner = new FakeCommandRunner();
        $runner->setOutput(
            "npm list -g '@anthropic-ai/claude-code' --depth=0 --json 2>/dev/null",
            '{"dependencies":{"@anthropic-ai/claude-code":{"version":"1.0.62"}}}',
        );

        $dep = new Dependency('claude', 'clients', '>=1.0', self::INSTALLER_NPM_GLOBAL, '@anthropic-ai/claude-code', ['npm']);
        $inspector = new StateInspector($runner);

        $version = $inspector->getInstalledVersion($dep);
        if ($version !== '1.0.62') {
            echo "FAIL testDetectsNpmPackage: expected 1.0.62, got {$version}\n";
            return 1;
        }

        echo "OK testDetectsNpmPackage\n";
        return 0;
    }

    private function testReturnsNullWhenNpmMissing(): int
    {
        $runner = new FakeCommandRunner();
        $runner->setOutput(
            "npm list -g '@openai/codex' --depth=0 --json 2>/dev/null",
            '{"dependencies":{}}',
        );

        $dep = new Dependency('codex', 'clients', '>=0.1', self::INSTALLER_NPM_GLOBAL, '@openai/codex', ['npm']);
        $inspector = new StateInspector($runner);

        $version = $inspector->getInstalledVersion($dep);
        if ($version !== null) {
            echo "FAIL testReturnsNullWhenNpmMissing: expected null, got {$version}\n";
            return 1;
        }

        echo "OK testReturnsNullWhenNpmMissing\n";
        return 0;
    }

    private function testDetectsGitHubReleaseBinary(): int
    {
        $runner = new FakeCommandRunner();
        $runner->setOutput("'opencode' --version 2>/dev/null", 'opencode 0.3.14');

        $dep = new Dependency('opencode', 'clients', '>=0.1', 'github-release', 'opencode', ['https://github.com/sst/opencode/releases']);
        $inspector = new StateInspector($runner);

        $version = $inspector->getInstalledVersion($dep);
        if ($version !== '0.3.14') {
            echo "FAIL testDetectsGitHubReleaseBinary: expected 0.3.14, got {$version}\n";
            return 1;
        }

        echo "OK testDetectsGitHubReleaseBinary\n";
        return 0;
    }

    private function testCachePreventsDoubleQuery(): int
    {
        $runner = new FakeCommandRunner();
        $runner->setOutput(self::CMD_DPKG_QUERY, '2.39.2');

        $dep = new Dependency('git', 'system', '>=2.30', 'apt', 'git', ['default']);
        $inspector = new StateInspector($runner);

        $inspector->getInstalledVersion($dep);
        $inspector->getInstalledVersion($dep);

        if ($runner->getCallCount() !== 1) {
            echo "FAIL testCachePreventsDoubleQuery: expected 1 call, got {$runner->getCallCount()}\n";
            return 1;
        }

        echo "OK testCachePreventsDoubleQuery\n";
        return 0;
    }

    private function testClearCacheAllowsRedetection(): int
    {
        $runner = new FakeCommandRunner();
        $runner->setOutput(self::CMD_DPKG_QUERY, '2.39.2');

        $dep = new Dependency('git', 'system', '>=2.30', 'apt', 'git', ['default']);
        $inspector = new StateInspector($runner);

        $inspector->getInstalledVersion($dep);
        $inspector->clearCache();
        $inspector->getInstalledVersion($dep);

        if ($runner->getCallCount() !== 2) {
            echo "FAIL testClearCacheAllowsRedetection: expected 2 calls, got {$runner->getCallCount()}\n";
            return 1;
        }

        echo "OK testClearCacheAllowsRedetection\n";
        return 0;
    }
}
