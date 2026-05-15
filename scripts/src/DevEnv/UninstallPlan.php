<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\DevEnv;

/**
 * Holds the complete uninstall plan for all lockfile dependencies.
 *
 * Built by SetupRunner; consumed by uninstall preview and apply steps.
 */
final class UninstallPlan
{
    /**
     * @param list<PlannedUninstall> $items All planned entries in lockfile order
     */
    public function __construct(
        public readonly array $items,
    ) {
    }

    /**
     * Returns true when any dep requires a system mutation (remove or restore).
     */
    public function hasActions(): bool
    {
        foreach ($this->items as $item) {
            if ($item->needsAction()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns only the entries that need a system mutation.
     *
     * @return list<PlannedUninstall>
     */
    public function toApply(): array
    {
        return array_values(array_filter(
            $this->items,
            static fn(PlannedUninstall $u): bool => $u->needsAction(),
        ));
    }

    /**
     * Returns side-effect file paths that should be removed after applying the plan.
     *
     * A path is only included when no KEEP entry still relies on it (spec §4.4:
     * "sauf si la source reste nécessaire à une dep encore présente").
     *
     * @return list<string>
     */
    public function sideEffectsToRemove(): array
    {
        // Collect side-effect paths still needed by KEEP entries
        $keptPaths = [];
        foreach ($this->items as $item) {
            if ($item->action !== PlannedUninstall::ACTION_KEEP || $item->entry->sideEffects === null) {
                continue;
            }
            if ($item->entry->sideEffects->aptRepo !== null) {
                $keptPaths[$item->entry->sideEffects->aptRepo] = true;
            }
            if ($item->entry->sideEffects->gpgKey !== null) {
                $keptPaths[$item->entry->sideEffects->gpgKey] = true;
            }
        }

        // Collect side-effect paths from REMOVE/RESTORE entries not in the kept set
        $toRemove = [];
        $seen = [];
        foreach ($this->items as $item) {
            if ($item->action === PlannedUninstall::ACTION_KEEP) {
                continue;
            }
            if ($item->entry->sideEffects === null || $item->entry->sideEffects->isEmpty()) {
                continue;
            }

            foreach ([$item->entry->sideEffects->aptRepo, $item->entry->sideEffects->gpgKey] as $path) {
                if ($path === null || isset($keptPaths[$path]) || isset($seen[$path])) {
                    continue;
                }
                $toRemove[] = $path;
                $seen[$path] = true;
            }
        }

        return $toRemove;
    }
}
