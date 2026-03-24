<?php

declare(strict_types=1);

namespace App\Domain\Project;

enum ModuleStatus: string
{
    case Active   = 'active';
    case Archived = 'archived';
    case Paused   = 'paused';
}
