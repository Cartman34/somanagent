<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Enum;

/**
 * Origin of a skill definition: imported from marketplace or custom.
 */
enum SkillSource: string
{
    case Imported = 'imported';
    case Custom   = 'custom';

    /**
     * Returns a human-readable label for the skill source.
     */
    public function label(): string
    {
        return match($this) {
            self::Imported => 'Imported (skills.sh)',
            self::Custom   => 'Custom',
        };
    }
}
