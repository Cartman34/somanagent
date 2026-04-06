<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Enum;

/**
 * Lifecycle status of a project module.
 */
enum ModuleStatus: string
{
    case Active   = 'active';
    case Archived = 'archived';

    /**
     * Returns a human-readable label for the module lifecycle status.
     */
    public function label(): string
    {
        return match($this) {
            self::Active   => 'Active',
            self::Archived => 'Archived',
        };
    }
}
