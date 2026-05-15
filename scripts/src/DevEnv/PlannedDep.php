<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\DevEnv;

use SoManAgent\Script\DevEnv\Model\Dependency;
use SoManAgent\Script\DevEnv\Model\LockEntry;

/**
 * Represents the planned install action for a single dependency.
 *
 * Built by InstallPlanner; consumed by PreviewBuilder and installer modules.
 */
final class PlannedDep
{
    public const ACTION_SKIP = 'skip';
    public const ACTION_INSTALL = 'install';
    public const ACTION_UPGRADE = 'upgrade';
    public const ACTION_BLOCKED = 'blocked';
    public const ACTION_CONFIRM = 'confirm';

    /**
     * @param LockEntry        $entry            Target lock entry (version to install)
     * @param Dependency|null  $dep              Manifest dependency (null for orphaned lockfile entries)
     * @param string           $action           One of the ACTION_* constants
     * @param string|null      $installedVersion Currently installed version, or null when not installed
     * @param string|null      $blockReason      Human-readable reason when action=blocked
     */
    public function __construct(
        public readonly LockEntry $entry,
        public readonly ?Dependency $dep,
        public readonly string $action,
        public readonly ?string $installedVersion,
        public readonly ?string $blockReason = null,
    ) {
    }

    /**
     * Returns true when this dep requires an actual system mutation (install or upgrade).
     */
    public function needsApply(): bool
    {
        return in_array($this->action, [self::ACTION_INSTALL, self::ACTION_UPGRADE, self::ACTION_CONFIRM], true);
    }

    /**
     * Returns true when the dep was already installed on the system before this run.
     */
    public function isPreExisting(): bool
    {
        return $this->entry->preExisting || $this->installedVersion !== null;
    }
}
