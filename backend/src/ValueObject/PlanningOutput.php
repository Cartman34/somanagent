<?php

declare(strict_types=1);

namespace App\ValueObject;

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
