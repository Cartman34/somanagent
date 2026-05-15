<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\DevEnv;

use SoManAgent\Script\DevEnv\Model\Dependency;
use SoManAgent\Script\DevEnv\Model\LockEntry;
use SoManAgent\Script\DevEnv\Model\Lockfile;
use SoManAgent\Script\DevEnv\Model\Manifest;

/**
 * Builds an InstallPlan from a lockfile, a manifest, and the current system state.
 *
 * For each lockfile entry:
 *   1. Reads the currently installed version via StateInspector.
 *   2. Compares normalized installed version to the locked target version.
 *   3. Applies the on_existing_below_min policy from the manifest when the
 *      installed version is strictly older than the locked version.
 *
 * All checks happen before the preview is shown (§3.2 anticipation complète).
 */
final class InstallPlanner
{
    /**
     * @param VersionConstraint $vc Version comparison helper
     */
    public function __construct(
        private readonly VersionConstraint $vc = new VersionConstraint(),
    ) {
    }

    /**
     * Builds and returns the complete install plan.
     *
     * @param Manifest       $manifest  Manifest with policies
     * @param \SoManAgent\Script\DevEnv\Model\Lockfile $lockfile  Lockfile with target versions
     * @param StateInspector $inspector Current system state detector
     */
    public function plan(Manifest $manifest, Lockfile $lockfile, StateInspector $inspector): InstallPlan
    {
        $depMap = [];
        foreach ($manifest->dependencies as $dep) {
            $depMap[$dep->key] = $dep;
        }

        $items = [];
        foreach ($lockfile->all() as $entry) {
            $dep = $depMap[$entry->key] ?? null;
            $currentVersion = $this->detectVersion($entry, $dep, $inspector);
            $items[] = $this->planEntry($entry, $dep, $manifest, $currentVersion);
        }

        return new InstallPlan($items);
    }

    /**
     * Detects the installed version for a lockfile entry.
     *
     * Uses the manifest Dependency when available; creates a minimal probe dep otherwise.
     */
    private function detectVersion(LockEntry $entry, ?Dependency $dep, StateInspector $inspector): ?string
    {
        if ($dep !== null) {
            return $inspector->getInstalledVersion($dep);
        }

        $probeDep = new Dependency(
            $entry->key,
            $entry->section,
            '>=0',
            $entry->installer,
            $entry->package,
            [],
        );

        return $inspector->getInstalledVersion($probeDep);
    }

    /**
     * Determines the planned action for one lockfile entry.
     */
    private function planEntry(
        LockEntry $entry,
        ?Dependency $dep,
        Manifest $manifest,
        ?string $currentVersion,
    ): PlannedDep {
        if ($currentVersion === null) {
            return new PlannedDep($entry, $dep, PlannedDep::ACTION_INSTALL, null);
        }

        $normalCurrent = $this->vc->normalize($currentVersion);
        $normalTarget = $this->vc->normalize($entry->version);

        if (version_compare($normalCurrent, $normalTarget, '>=')) {
            return new PlannedDep($entry, $dep, PlannedDep::ACTION_SKIP, $currentVersion);
        }

        // Current version is strictly older than target → upgrade needed
        if ($dep === null) {
            // No manifest dep to read policy from — default to upgrade
            return new PlannedDep($entry, $dep, PlannedDep::ACTION_UPGRADE, $currentVersion);
        }

        $policy = $manifest->resolveOnExistingBelowMin($dep);

        return match ($policy) {
            'upgrade' => new PlannedDep($entry, $dep, PlannedDep::ACTION_UPGRADE, $currentVersion),
            'error' => new PlannedDep(
                $entry,
                $dep,
                PlannedDep::ACTION_BLOCKED,
                $currentVersion,
                sprintf(
                    "policy=error: %s is at %s, locked target is %s — upgrade not permitted automatically",
                    $entry->key,
                    $currentVersion,
                    $entry->version,
                ),
            ),
            'confirm' => new PlannedDep($entry, $dep, PlannedDep::ACTION_CONFIRM, $currentVersion),
            default => new PlannedDep($entry, $dep, PlannedDep::ACTION_UPGRADE, $currentVersion),
        };
    }
}
