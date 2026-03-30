<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Enum;

enum WorkflowStepTransitionMode: string
{
    case Manual = 'manual';
    case Automatic = 'automatic';
}
