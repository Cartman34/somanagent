<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Ticket;

use App\Enum\TaskPriority;

/**
 * Input DTO for creating a project request (user story via the request flow).
 */
final class CreateTicketRequestDto
{
    /**
     * @param string       $title       Request title
     * @param string       $description Request description
     * @param TaskPriority $priority    Request priority
     */
    public function __construct(
        public readonly string $title,
        public readonly string $description,
        public readonly TaskPriority $priority,
    ) {}

    /**
     * @throws \InvalidArgumentException with a short domain code on validation failure
     */
    public static function fromArray(array $data): self
    {
        if (empty($data['title'])) {
            throw new \InvalidArgumentException('title_required');
        }

        if (empty($data['description'])) {
            throw new \InvalidArgumentException('description_required');
        }

        return new self(
            title: (string) $data['title'],
            description: (string) $data['description'],
            priority: TaskPriority::from($data['priority'] ?? TaskPriority::Medium->value),
        );
    }
}
