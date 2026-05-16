<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\DevEnv;

/**
 * Holds the complete install plan for all lockfile dependencies.
 *
 * Built by InstallPlanner; consumed by PreviewBuilder, SetupRunner, and installer modules.
 */
final class InstallPlan
{
    /**
     * @param list<PlannedDep> $items All planned deps in lockfile order
     */
    public function __construct(
        public readonly array $items,
    ) {
    }

    /**
     * Returns true when any dep has action=blocked (prevents install from proceeding).
     */
    public function hasBlocked(): bool
    {
        foreach ($this->items as $dep) {
            if ($dep->action === PlannedDep::ACTION_BLOCKED) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns true when any dep requires a system mutation (install or upgrade).
     */
    public function hasActions(): bool
    {
        foreach ($this->items as $dep) {
            if ($dep->needsApply()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns only the blocked deps.
     *
     * @return list<PlannedDep>
     */
    public function blocked(): array
    {
        return array_values(array_filter(
            $this->items,
            static fn(PlannedDep $d): bool => $d->action === PlannedDep::ACTION_BLOCKED,
        ));
    }

    /**
     * Returns only the deps that need a system mutation.
     *
     * @return list<PlannedDep>
     */
    public function toApply(): array
    {
        return array_values(array_filter(
            $this->items,
            static fn(PlannedDep $d): bool => $d->needsApply(),
        ));
    }
}
