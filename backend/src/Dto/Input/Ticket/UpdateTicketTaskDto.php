<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Ticket;

use App\Enum\TaskPriority;

/**
 * Input DTO for updating a ticket task (all fields optional).
 */
final class UpdateTicketTaskDto
{
    /**
     * @param ?string       $title           Updated title or null to keep current
     * @param ?string       $description     Updated description
     * @param ?TaskPriority $priority        Updated priority or null to keep current
     * @param ?string       $actionKey       Updated action key
     * @param ?string       $assignedAgentId Updated assigned agent UUID
     */
    public function __construct(
        public readonly ?string $title,
        public readonly ?string $description,
        public readonly ?TaskPriority $priority,
        public readonly ?string $actionKey,
        public readonly ?string $assignedAgentId,
    ) {}

    /**
     * Creates an instance from raw request data. No required fields.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            title: isset($data['title']) && $data['title'] !== '' ? (string) $data['title'] : null,
            description: isset($data['description']) && $data['description'] !== '' ? (string) $data['description'] : null,
            priority: isset($data['priority']) ? TaskPriority::from((string) $data['priority']) : null,
            actionKey: isset($data['actionKey']) && $data['actionKey'] !== '' ? (string) $data['actionKey'] : null,
            assignedAgentId: isset($data['assignedAgentId']) && $data['assignedAgentId'] !== '' ? (string) $data['assignedAgentId'] : null,
        );
    }
}
