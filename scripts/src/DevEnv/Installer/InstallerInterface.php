<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\DevEnv\Installer;

use Sowapps\SoManAgent\Script\DevEnv\Model\LockEntry;
use Sowapps\SoManAgent\Script\DevEnv\PlannedDep;


/**
 * Contract for a dependency installer module.
 *
 * Each module handles one category of dependencies (e.g. system apt packages,
 * Docker packages, npm clients). The SetupRunner discovers which module handles
 * each PlannedDep via supports(), groups them, and delegates installation.
 */
interface InstallerInterface
{
    /**
     * Returns true when this installer handles the given planned dep.
     */
    public function supports(PlannedDep $dep): bool;

    /**
     * Returns the shell commands that would be run for the given batch of deps.
     *
     * Used during dry-run mode to display the plan without applying mutations.
     *
     * @param list<PlannedDep> $deps Deps already filtered to those this installer supports
     * @return list<string>
     */
    public function getSimulatedCommands(array $deps): array;

    /**
     * Installs or upgrades the given batch of deps and returns updated lock entries.
     *
     * The caller is responsible for writing the returned entries back to the lockfile.
     *
     * @param list<PlannedDep> $deps Deps already filtered to those this installer supports
     * @return list<LockEntry> Updated lock entries with resolved_at, previous_version, side_effects
     * @throws \RuntimeException on installation failure
     */
    public function install(array $deps): array;
}
