<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Ticket;

/**
 * Input DTO for resuming a ticket task (no body fields required).
 */
final class ResumeTicketTaskDto
{
    /**
     * Creates an empty DTO instance (no body fields expected).
     */
    public function __construct() {}

    /**
     * Builds a DTO from raw input data.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self();
    }
}
