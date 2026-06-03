<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\ValueObject;

/**
 * Parsed result of the JSON output from the tech-planning skill.
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
