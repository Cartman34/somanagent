<?php

declare(strict_types=1);

namespace App\Port;

interface SkillPort
{
    /**
     * Importe un skill depuis le registry (ex: "anthropics/code-reviewer").
     * Retourne les métadonnées parsées du SKILL.md.
     *
     * @return array{slug: string, name: string, description: string, content: string, filePath: string}
     */
    public function import(string $ownerAndName): array;

    /**
     * Liste les skills disponibles dans le registry.
     *
     * @return array<array{slug: string, name: string, description: string}>
     */
    public function search(string $query = ''): array;
}
