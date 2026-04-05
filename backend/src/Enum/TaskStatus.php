<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Enum;

enum TaskStatus: string
{
    case Backlog          = 'backlog';
    case Todo             = 'todo';
    case AwaitingDispatch = 'awaiting_dispatch';
    case InProgress       = 'in_progress';
    case Review           = 'review';
    case Done             = 'done';
    case Cancelled        = 'cancelled';

    /**
     * Returns whether this status should be treated as terminal in business flows.
     */
    public function isDone(): bool
    {
        return $this === self::Done || $this === self::Cancelled;
    }
}
