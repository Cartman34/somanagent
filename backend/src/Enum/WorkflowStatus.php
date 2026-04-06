<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Enum;

/**
 * Lifecycle status of a workflow determining whether it can be assigned to projects.
 */
enum WorkflowStatus: string
{
    case Validated = 'validated';
    case Locked    = 'locked';

    /**
     * Returns a human-readable label for the workflow lifecycle status.
     */
    public function label(): string
    {
        return match($this) {
            self::Validated => 'Validated',
            self::Locked    => 'Locked',
        };
    }

    /**
     * Indicates whether workflows in this status may be used at runtime.
     */
    public function isUsable(): bool
    {
        return $this === self::Validated || $this === self::Locked;
    }
}
