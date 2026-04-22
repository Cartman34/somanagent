<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Ticket;

use App\Enum\TaskStatus;

/**
 * Input DTO for changing the status of a ticket or ticket task.
 */
final class ChangeStatusDto
{
    /**
     * @param TaskStatus $status New status value
     */
    public function __construct(
        public readonly TaskStatus $status,
    ) {}

    /**
     * @throws \InvalidArgumentException with a short domain code on validation failure
     */
    public static function fromArray(array $data): self
    {
        if (empty($data['status'])) {
            throw new \InvalidArgumentException('status_required');
        }

        return new self(
            status: TaskStatus::from((string) $data['status']),
        );
    }
}
