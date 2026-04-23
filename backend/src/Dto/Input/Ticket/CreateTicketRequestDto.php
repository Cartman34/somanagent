<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Ticket;

use App\Enum\TaskPriority;
use App\Exception\ValidationException;

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
     * @throws ValidationException with accumulated validation errors
     */
    public static function fromArray(array $data): self
    {
        $errors = [];

        if (empty($data['title'])) {
            $errors[] = ['field' => 'title', 'code' => 'ticket.validation.title_required'];
        }

        if (empty($data['description'])) {
            $errors[] = ['field' => 'description', 'code' => 'ticket.validation.description_required'];
        }

        $priority = TaskPriority::Medium;
        if (isset($data['priority']) && $data['priority'] !== '') {
            $p = TaskPriority::tryFrom((string) $data['priority']);
            if ($p !== null) {
                $priority = $p;
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new self(
            title: (string) $data['title'],
            description: (string) $data['description'],
            priority: $priority,
        );
    }
}
