<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Domain\Skill\Skill;

/**
 * Port de gestion des skills.
 * Abstrait la source des skills (skills.sh registry, système de fichiers local, etc.)
 */
interface SkillPort
{
    /**
     * Importe un skill depuis le registry skills.sh (ex: "owner/skill-name").
     * Copie le SKILL.md dans le dossier skills/imported/.
     */
    public function importFromRegistry(string $skillRef): Skill;

    /**
     * Liste tous les skills disponibles localement.
     */
    public function listLocal(): array;

    /**
     * Recherche des skills dans le registry skills.sh.
     */
    public function searchRegistry(string $keyword): array;

    /**
     * Charge un skill depuis son chemin local.
     */
    public function loadFromPath(string $path): Skill;
}
