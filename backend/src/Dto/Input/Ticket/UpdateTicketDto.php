<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Ticket;

use App\Enum\TaskPriority;

/**
 * Input DTO for updating a ticket (all fields optional).
 */
final class UpdateTicketDto
{
    /**
     * @param ?string       $title       Updated title or null to keep current
     * @param ?string       $description Updated description
     * @param ?TaskPriority $priority    Updated priority or null to keep current
     * @param ?string       $featureId   Updated feature UUID
     */
    public function __construct(
        public readonly ?string $title,
        public readonly ?string $description,
        public readonly ?TaskPriority $priority,
        public readonly ?string $featureId,
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
            featureId: isset($data['featureId']) && $data['featureId'] !== '' ? (string) $data['featureId'] : null,
        );
    }
}
