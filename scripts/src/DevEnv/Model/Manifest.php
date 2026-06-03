<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\DevEnv\Model;

/**
 * Represents the parsed dependency manifest.
 *
 * Contains default policy values and all declared dependencies.
 */
final class Manifest
{
    public const DEFAULT_ON_EXISTING_BELOW_MIN = 'confirm';
    public const DEFAULT_ON_UNINSTALL_PRE_EXISTING = 'keep';

    /**
     * @param string $onExistingBelowMin Default policy when existing version is below minimum
     * @param string $onUninstallPreExisting Default policy for pre-existing packages on uninstall
     * @param list<Dependency> $dependencies All declared dependencies in manifest order
     */
    public function __construct(
        public readonly string $onExistingBelowMin = self::DEFAULT_ON_EXISTING_BELOW_MIN,
        public readonly string $onUninstallPreExisting = self::DEFAULT_ON_UNINSTALL_PRE_EXISTING,
        public readonly array $dependencies = [],
    ) {
    }

    /**
     * Returns the resolved on_existing_below_min policy for a dependency.
     *
     * The dependency-level override takes priority over the manifest default.
     */
    public function resolveOnExistingBelowMin(Dependency $dep): string
    {
        return $dep->onExistingBelowMin ?? $this->onExistingBelowMin;
    }

    /**
     * Returns the resolved on_uninstall_pre_existing policy for a dependency.
     *
     * The dependency-level override takes priority over the manifest default.
     */
    public function resolveOnUninstallPreExisting(Dependency $dep): string
    {
        return $dep->onUninstallPreExisting ?? $this->onUninstallPreExisting;
    }
}
