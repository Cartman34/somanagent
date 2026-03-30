<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Enum;

enum WorkflowStatus: string
{
    case Validated = 'validated';
    case Locked    = 'locked';

    public function label(): string
    {
        return match($this) {
            self::Validated => 'Validated',
            self::Locked    => 'Locked',
        };
    }

    public function isUsable(): bool
    {
        return $this === self::Validated || $this === self::Locked;
    }
}
