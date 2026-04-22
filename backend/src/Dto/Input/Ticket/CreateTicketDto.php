<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Ticket;

use App\Enum\TaskPriority;
use App\Enum\TaskType;

/**
 * Input DTO for creating a ticket.
 */
final class CreateTicketDto
{
    /**
     * @param string       $title       Ticket title
     * @param TaskType     $type        Ticket type
     * @param TaskPriority $priority    Ticket priority
     * @param ?string      $description Optional description
     * @param ?string      $featureId   Optional feature UUID
     */
    public function __construct(
        public readonly string $title,
        public readonly TaskType $type,
        public readonly TaskPriority $priority,
        public readonly ?string $description,
        public readonly ?string $featureId,
    ) {}

    /**
     * @throws \InvalidArgumentException with a short domain code on validation failure
     */
    public static function fromArray(array $data): self
    {
        if (empty($data['title'])) {
            throw new \InvalidArgumentException('title_required');
        }

        return new self(
            title: (string) $data['title'],
            type: TaskType::from($data['type'] ?? TaskType::UserStory->value),
            priority: TaskPriority::from($data['priority'] ?? TaskPriority::Medium->value),
            description: isset($data['description']) && $data['description'] !== '' ? (string) $data['description'] : null,
            featureId: isset($data['featureId']) && $data['featureId'] !== '' ? (string) $data['featureId'] : null,
        );
    }
}
