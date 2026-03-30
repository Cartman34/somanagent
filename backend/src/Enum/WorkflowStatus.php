<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Enum;

enum WorkflowStatus: string
{
    case Draft     = 'draft';
    case Validated = 'validated';
    case Locked    = 'locked';

    public function label(): string
    {
        return match($this) {
            self::Draft     => 'Brouillon',
            self::Validated => 'Validé',
            self::Locked    => 'Verrouillé',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function isUsable(): bool
    {
        return $this === self::Validated || $this === self::Locked;
    }
}
