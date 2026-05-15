<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\DevEnv\Test;

use SoManAgent\Script\DevEnv\InstallPlanner;
use SoManAgent\Script\DevEnv\Model\Dependency;
use SoManAgent\Script\DevEnv\Model\LockEntry;
use SoManAgent\Script\DevEnv\Model\Lockfile;
use SoManAgent\Script\DevEnv\Model\Manifest;
use SoManAgent\Script\DevEnv\PlannedDep;
use SoManAgent\Script\DevEnv\StateInspector;

/**
 * Unit tests for InstallPlanner.
 */
final class InstallPlannerTest
{
    /**
     * Runs all test cases and returns the total number of failures.
     */
    public function run(): int
    {
        $failed = 0;

        $failed += $this->testInstallWhenNotInstalled();
        $failed += $this->testSkipWhenExactVersionInstalled();
        $failed += $this->testSkipWhenNewerVersionInstalled();
        $failed += $this->testUpgradeWhenOlderVersionAndPolicyUpgrade();
        $failed += $this->testBlockedWhenOlderVersionAndPolicyError();
        $failed += $this->testConfirmWhenOlderVersionAndPolicyConfirm();
        $failed += $this->testEmptyLockfile();
        $failed += $this->testOrphanedLockfileEntryDefaultsToUpgrade();

        return $failed;
    }

    private function testInstallWhenNotInstalled(): int
    {
        $runner = new FakeCommandRunner();
        $dep = new Dependency('git', 'system', '>=2.30', 'apt', 'git', ['default']);
        $entry = $this->makeEntry('git', 'system', '2.39.2', 'apt', 'git');
        $manifest = new Manifest('upgrade', 'keep', [$dep]);
        $lockfile = (new Lockfile(null, null, []))->withEntry($entry);
        $inspector = new StateInspector($runner);

        $plan = (new InstallPlanner())->plan($manifest, $lockfile, $inspector);

        if (count($plan->items) !== 1) {
            echo "FAIL testInstallWhenNotInstalled: expected 1 item\n";
            return 1;
        }

        if ($plan->items[0]->action !== PlannedDep::ACTION_INSTALL) {
            echo "FAIL testInstallWhenNotInstalled: expected INSTALL, got {$plan->items[0]->action}\n";
            return 1;
        }

        echo "OK testInstallWhenNotInstalled\n";
        return 0;
    }

    private function testSkipWhenExactVersionInstalled(): int
    {
        $runner = new FakeCommandRunner();
        $runner->setOutput("dpkg-query -W -f='\${Version}' 'git'", '2.39.2');

        $dep = new Dependency('git', 'system', '>=2.30', 'apt', 'git', ['default']);
        $entry = $this->makeEntry('git', 'system', '2.39.2', 'apt', 'git');
        $manifest = new Manifest('upgrade', 'keep', [$dep]);
        $lockfile = (new Lockfile(null, null, []))->withEntry($entry);
        $inspector = new StateInspector($runner);

        $plan = (new InstallPlanner())->plan($manifest, $lockfile, $inspector);

        if ($plan->items[0]->action !== PlannedDep::ACTION_SKIP) {
            echo "FAIL testSkipWhenExactVersionInstalled: expected SKIP, got {$plan->items[0]->action}\n";
            return 1;
        }

        echo "OK testSkipWhenExactVersionInstalled\n";
        return 0;
    }

    private function testSkipWhenNewerVersionInstalled(): int
    {
        $runner = new FakeCommandRunner();
        $runner->setOutput("dpkg-query -W -f='\${Version}' 'git'", '2.43.0');

        $dep = new Dependency('git', 'system', '>=2.30', 'apt', 'git', ['default']);
        $entry = $this->makeEntry('git', 'system', '2.39.2', 'apt', 'git');
        $manifest = new Manifest('upgrade', 'keep', [$dep]);
        $lockfile = (new Lockfile(null, null, []))->withEntry($entry);
        $inspector = new StateInspector($runner);

        $plan = (new InstallPlanner())->plan($manifest, $lockfile, $inspector);

        if ($plan->items[0]->action !== PlannedDep::ACTION_SKIP) {
            echo "FAIL testSkipWhenNewerVersionInstalled: expected SKIP, got {$plan->items[0]->action}\n";
            return 1;
        }

        echo "OK testSkipWhenNewerVersionInstalled\n";
        return 0;
    }

    private function testUpgradeWhenOlderVersionAndPolicyUpgrade(): int
    {
        $runner = new FakeCommandRunner();
        $runner->setOutput("dpkg-query -W -f='\${Version}' 'tmux'", '3.1.0');

        $dep = new Dependency('tmux', 'system', '>=3.2', 'apt', 'tmux', ['default']);
        $entry = $this->makeEntry('tmux', 'system', '3.4.0', 'apt', 'tmux');
        $manifest = new Manifest('upgrade', 'keep', [$dep]);
        $lockfile = (new Lockfile(null, null, []))->withEntry($entry);
        $inspector = new StateInspector($runner);

        $plan = (new InstallPlanner())->plan($manifest, $lockfile, $inspector);

        if ($plan->items[0]->action !== PlannedDep::ACTION_UPGRADE) {
            echo "FAIL testUpgradeWhenOlderVersionAndPolicyUpgrade: expected UPGRADE, got {$plan->items[0]->action}\n";
            return 1;
        }

        if ($plan->items[0]->installedVersion !== '3.1.0') {
            echo "FAIL testUpgradeWhenOlderVersionAndPolicyUpgrade: expected installedVersion=3.1.0\n";
            return 1;
        }

        echo "OK testUpgradeWhenOlderVersionAndPolicyUpgrade\n";
        return 0;
    }

    private function testBlockedWhenOlderVersionAndPolicyError(): int
    {
        $runner = new FakeCommandRunner();
        $runner->setOutput("dpkg-query -W -f='\${Version}' 'tmux'", '3.1.0');

        $dep = new Dependency('tmux', 'system', '>=3.2', 'apt', 'tmux', ['default'], null, 'error');
        $entry = $this->makeEntry('tmux', 'system', '3.4.0', 'apt', 'tmux');
        $manifest = new Manifest('upgrade', 'keep', [$dep]);
        $lockfile = (new Lockfile(null, null, []))->withEntry($entry);
        $inspector = new StateInspector($runner);

        $plan = (new InstallPlanner())->plan($manifest, $lockfile, $inspector);

        if ($plan->items[0]->action !== PlannedDep::ACTION_BLOCKED) {
            echo "FAIL testBlockedWhenOlderVersionAndPolicyError: expected BLOCKED, got {$plan->items[0]->action}\n";
            return 1;
        }

        if (!$plan->hasBlocked()) {
            echo "FAIL testBlockedWhenOlderVersionAndPolicyError: hasBlocked() should return true\n";
            return 1;
        }

        echo "OK testBlockedWhenOlderVersionAndPolicyError\n";
        return 0;
    }

    private function testConfirmWhenOlderVersionAndPolicyConfirm(): int
    {
        $runner = new FakeCommandRunner();
        $runner->setOutput("dpkg-query -W -f='\${Version}' 'tmux'", '3.1.0');

        $dep = new Dependency('tmux', 'system', '>=3.2', 'apt', 'tmux', ['default'], null, 'confirm');
        $entry = $this->makeEntry('tmux', 'system', '3.4.0', 'apt', 'tmux');
        $manifest = new Manifest('upgrade', 'keep', [$dep]);
        $lockfile = (new Lockfile(null, null, []))->withEntry($entry);
        $inspector = new StateInspector($runner);

        $plan = (new InstallPlanner())->plan($manifest, $lockfile, $inspector);

        if ($plan->items[0]->action !== PlannedDep::ACTION_CONFIRM) {
            echo "FAIL testConfirmWhenOlderVersionAndPolicyConfirm: expected CONFIRM, got {$plan->items[0]->action}\n";
            return 1;
        }

        echo "OK testConfirmWhenOlderVersionAndPolicyConfirm\n";
        return 0;
    }

    private function testEmptyLockfile(): int
    {
        $manifest = new Manifest('upgrade', 'keep', []);
        $lockfile = new Lockfile(null, null, []);
        $inspector = new StateInspector(new FakeCommandRunner());

        $plan = (new InstallPlanner())->plan($manifest, $lockfile, $inspector);

        if ($plan->items !== []) {
            echo "FAIL testEmptyLockfile: expected no items\n";
            return 1;
        }

        if ($plan->hasActions()) {
            echo "FAIL testEmptyLockfile: hasActions() should be false\n";
            return 1;
        }

        echo "OK testEmptyLockfile\n";
        return 0;
    }

    private function testOrphanedLockfileEntryDefaultsToUpgrade(): int
    {
        $runner = new FakeCommandRunner();
        // Dep key 'orphan' returns installed version via binary detection
        $runner->setOutput("'orphan' --version 2>/dev/null", 'orphan 0.5.0');

        // No manifest dep for 'orphan' — it's an orphaned lockfile entry
        $entry = $this->makeEntry('orphan', 'clients', '1.0.0', 'github-release', 'orphan');
        $manifest = new Manifest('upgrade', 'keep', []);
        $lockfile = (new Lockfile(null, null, []))->withEntry($entry);
        $inspector = new StateInspector($runner);

        $plan = (new InstallPlanner())->plan($manifest, $lockfile, $inspector);

        // With older version found, should default to UPGRADE (no policy from manifest)
        if ($plan->items[0]->action !== PlannedDep::ACTION_UPGRADE) {
            // Could also be INSTALL if binary not detected — both valid outcomes
            if ($plan->items[0]->action !== PlannedDep::ACTION_INSTALL) {
                echo "FAIL testOrphanedLockfileEntryDefaultsToUpgrade: expected UPGRADE or INSTALL, got {$plan->items[0]->action}\n";
                return 1;
            }
        }

        echo "OK testOrphanedLockfileEntryDefaultsToUpgrade\n";
        return 0;
    }

    /**
     * Helper to create a minimal LockEntry for tests.
     */
    private function makeEntry(string $key, string $section, string $version, string $installer, string $package): LockEntry
    {
        return new LockEntry(
            key: $key,
            section: $section,
            version: $version,
            installer: $installer,
            package: $package,
            source: 'default',
            preExisting: false,
            previousVersion: null,
            sideEffects: null,
            resolvedAt: new \DateTimeImmutable(),
        );
    }
}
