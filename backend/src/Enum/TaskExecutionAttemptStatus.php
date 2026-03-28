<?php

declare(strict_types=1);

namespace App\Enum;

enum TaskExecutionAttemptStatus: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
