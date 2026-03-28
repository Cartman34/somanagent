<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Enum\TaskPriority;

/**
 * A single task extracted from the lead-tech planning output.
 */
final class PlanningTask
{
    /**
     * @param int[] $dependsOn Zero-based indices of prerequisite tasks in the final plan order
     */
    public function __construct(
        public readonly string $title,
        public readonly string $description,
        public readonly string $role,
        public readonly TaskPriority $priority,
        public readonly array $dependsOn,
    ) {}
}
