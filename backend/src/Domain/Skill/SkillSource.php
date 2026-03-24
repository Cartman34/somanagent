<?php

declare(strict_types=1);

namespace App\Domain\Skill;

enum SkillSource: string
{
    case Imported = 'imported';  // Vient du registry skills.sh
    case Custom   = 'custom';    // Créé dans SoManAgent
}
