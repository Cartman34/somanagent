<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Enum;

enum SkillSource: string
{
    case Imported = 'imported';
    case Custom   = 'custom';

    public function label(): string
    {
        return match($this) {
            self::Imported => 'Importé (skills.sh)',
            self::Custom   => 'Personnalisé',
        };
    }
}
