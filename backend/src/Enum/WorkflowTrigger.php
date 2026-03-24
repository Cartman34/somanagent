<?php

declare(strict_types=1);

namespace App\Enum;

enum WorkflowTrigger: string
{
    case Manual    = 'manual';
    case VcsEvent  = 'vcs_event';
    case Scheduled = 'scheduled';

    public function label(): string
    {
        return match($this) {
            self::Manual    => 'Manuel',
            self::VcsEvent  => 'Événement VCS (PR/MR)',
            self::Scheduled => 'Planifié',
        };
    }
}
