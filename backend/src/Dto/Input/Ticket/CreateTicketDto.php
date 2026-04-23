<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Ticket;

use App\Enum\TaskPriority;
use App\Enum\TaskType;
use App\Exception\ValidationException;

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
     * @throws ValidationException with accumulated validation errors
     */
    public static function fromArray(array $data): self
    {
        $errors = [];

        if (empty($data['title'])) {
            $errors[] = ['field' => 'title', 'code' => 'ticket.validation.title_required'];
        }

        $type = TaskType::UserStory;
        if (isset($data['type']) && $data['type'] !== '') {
            $t = TaskType::tryFrom((string) $data['type']);
            if ($t === null) {
                $errors[] = ['field' => 'type', 'code' => 'ticket.validation.type_invalid'];
            } else {
                $type = $t;
            }
        }

        $priority = TaskPriority::Medium;
        if (isset($data['priority']) && $data['priority'] !== '') {
            $p = TaskPriority::tryFrom((string) $data['priority']);
            if ($p === null) {
                $errors[] = ['field' => 'priority', 'code' => 'ticket.validation.priority_invalid'];
            } else {
                $priority = $p;
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new self(
            title: (string) $data['title'],
            type: $type,
            priority: $priority,
            description: isset($data['description']) && $data['description'] !== '' ? (string) $data['description'] : null,
            featureId: isset($data['featureId']) && $data['featureId'] !== '' ? (string) $data['featureId'] : null,
        );
    }
}
