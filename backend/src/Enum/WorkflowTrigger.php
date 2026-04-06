<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Enum;

/**
 * Trigger mode determining how a workflow starts.
 */
enum WorkflowTrigger: string
{
    case Manual    = 'manual';
    case VcsEvent  = 'vcs_event';
    case Scheduled = 'scheduled';

    /**
     * Returns a human-readable label for the workflow trigger.
     */
    public function label(): string
    {
        return match($this) {
            self::Manual    => 'Manual',
            self::VcsEvent  => 'VCS event (PR/MR)',
            self::Scheduled => 'Scheduled',
        };
    }
}
