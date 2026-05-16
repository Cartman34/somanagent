<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\DevEnv;

use SoManAgent\Script\DevEnv\Model\LockEntry;

/**
 * Represents the planned uninstall action for a single dependency.
 *
 * Built by SetupRunner::buildUninstallPlan(); consumed by uninstall preview
 * rendering and the apply step.
 */
final class PlannedUninstall
{
    public const ACTION_REMOVE = 'remove';
    public const ACTION_RESTORE = 'restore';
    public const ACTION_KEEP = 'keep';

    /**
     * @param string $action      One of the ACTION_* constants
     * @param string $policySource Human-readable origin of the resolved policy (for display)
     */
    public function __construct(
        public readonly LockEntry $entry,
        public readonly string $action,
        public readonly string $policySource,
    ) {
    }

    /**
     * Returns true when this dep requires a system mutation (remove or restore).
     */
    public function needsAction(): bool
    {
        return $this->action !== self::ACTION_KEEP;
    }
}
