<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Ticket;

/**
 * Input DTO for manually executing a ticket task.
 */
final class ExecuteTicketTaskDto
{
    /**
     * @param ?string $agentId Optional agent UUID to use for execution
     */
    public function __construct(
        public readonly ?string $agentId,
    ) {}

    /**
     * Creates an instance from raw request data.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            agentId: isset($data['agentId']) && $data['agentId'] !== '' ? (string) $data['agentId'] : null,
        );
    }
}
