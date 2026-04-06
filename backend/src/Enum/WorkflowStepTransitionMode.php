<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Enum;

/**
 * Mode controlling whether a workflow step transition requires manual approval or is automatic.
 */
enum WorkflowStepTransitionMode: string
{
    case Manual = 'manual';
    case Automatic = 'automatic';
}
