<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Enum\TaskPriority;

/**
 * Résultat parsé de la sortie JSON du skill tech-planning.
 */
final class PlanningOutput
{
    /**
     * @param PlanningTask[] $tasks
     * @param array<array{file: string, note: string}> $specUpdates
     */
    public function __construct(
        public readonly string $branch,
        public readonly bool   $needsDesign,
        public readonly array  $tasks,
        public readonly array  $specUpdates = [],
    ) {}
}

/**
 * Une tâche issue du plan du lead tech.
 */
final class PlanningTask
{
    /**
     * @param int[] $dependsOn Indices (0-based) des tâches dont celle-ci dépend
     */
    public function __construct(
        public readonly string       $title,
        public readonly string       $description,
        public readonly string       $role,
        public readonly TaskPriority $priority,
        public readonly array        $dependsOn,
    ) {}
}
