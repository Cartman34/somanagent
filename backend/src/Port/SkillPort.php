<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Port;

/**
 * Hexagonal port for skill registry operations (import, search).
 */
interface SkillPort
{
    /**
     * Imports a skill from the registry (for example: "anthropics/code-reviewer").
     * Returns the parsed metadata extracted from SKILL.md.
     *
     * @return array{slug: string, name: string, description: string, content: string, filePath: string}
     */
    public function import(string $ownerAndName): array;

    /**
     * Lists the skills available from the registry.
     *
     * @return array<array{slug: string, name: string, description: string}>
     */
    public function search(string $query = ''): array;
}
