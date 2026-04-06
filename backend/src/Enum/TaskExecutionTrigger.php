<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Enum;

/**
 * Trigger mechanism that initiated a task execution.
 */
enum TaskExecutionTrigger: string
{
    case Auto = 'auto';
    case Manual = 'manual';
    case Rework = 'rework';
    case Redispatch = 'redispatch';
}
