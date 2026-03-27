<?php

declare(strict_types=1);

namespace App\Enum;

enum TaskStatus: string
{
    case Backlog    = 'backlog';
    case Todo       = 'todo';
    case InProgress = 'in_progress';
    case Review     = 'review';
    case Done       = 'done';
    case Cancelled  = 'cancelled';

    public function isDone(): bool
    {
        return $this === self::Done || $this === self::Cancelled;
    }
}
