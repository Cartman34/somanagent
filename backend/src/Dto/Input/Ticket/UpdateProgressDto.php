<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Ticket;

/**
 * Input DTO for updating the progress of a ticket task.
 */
final class UpdateProgressDto
{
    /**
     * @param int $progress Progress value (0-100)
     */
    public function __construct(
        public readonly int $progress,
    ) {}

    /**
     * Creates an instance from raw request data.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            progress: (int) ($data['progress'] ?? 0),
        );
    }
}
