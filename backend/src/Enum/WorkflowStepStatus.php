<?php

declare(strict_types=1);

namespace App\Enum;

enum WorkflowStepStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Done    = 'done';
    case Error   = 'error';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match($this) {
            self::Pending => 'En attente',
            self::Running => 'En cours',
            self::Done    => 'Terminé',
            self::Error   => 'Erreur',
            self::Skipped => 'Ignoré',
        };
    }
}
