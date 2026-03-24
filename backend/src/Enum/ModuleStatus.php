<?php

declare(strict_types=1);

namespace App\Enum;

enum ModuleStatus: string
{
    case Active   = 'active';
    case Archived = 'archived';

    public function label(): string
    {
        return match($this) {
            self::Active   => 'Actif',
            self::Archived => 'Archivé',
        };
    }
}
