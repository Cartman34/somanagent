<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Enum;

enum TaskExecutionStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Retrying = 'retrying';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case DeadLetter = 'dead_letter';
    case Cancelled = 'cancelled';
}
