<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Ticket;

use App\Enum\TaskPriority;

/**
 * Input DTO for reprioritizing a ticket or ticket task.
 */
final class ReprioritizeDto
{
    /**
     * @param TaskPriority $priority New priority value
     */
    public function __construct(
        public readonly TaskPriority $priority,
    ) {}

    /**
     * @throws \InvalidArgumentException with a short domain code on validation failure
     */
    public static function fromArray(array $data): self
    {
        if (empty($data['priority'])) {
            throw new \InvalidArgumentException('priority_required');
        }

        return new self(
            priority: TaskPriority::from((string) $data['priority']),
        );
    }
}
