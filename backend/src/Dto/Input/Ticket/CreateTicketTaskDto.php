<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Ticket;

use App\Enum\TaskPriority;
use App\Exception\ValidationException;

/**
 * Input DTO for creating a ticket task.
 */
final class CreateTicketTaskDto
{
    /**
     * @param string       $title           Task title
     * @param string       $actionKey       Agent action key
     * @param TaskPriority $priority        Task priority
     * @param ?string      $description     Optional description
     * @param ?string      $parentTaskId    Optional parent task UUID
     * @param ?string      $assignedAgentId Optional assigned agent UUID
     */
    public function __construct(
        public readonly string $title,
        public readonly string $actionKey,
        public readonly TaskPriority $priority,
        public readonly ?string $description,
        public readonly ?string $parentTaskId,
        public readonly ?string $assignedAgentId,
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

        if (empty($data['actionKey'])) {
            $errors[] = ['field' => 'actionKey', 'code' => 'ticket.validation.action_key_required'];
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
            actionKey: (string) $data['actionKey'],
            priority: $priority,
            description: isset($data['description']) && $data['description'] !== '' ? (string) $data['description'] : null,
            parentTaskId: isset($data['parentTaskId']) && $data['parentTaskId'] !== '' ? (string) $data['parentTaskId'] : null,
            assignedAgentId: isset($data['assignedAgentId']) && $data['assignedAgentId'] !== '' ? (string) $data['assignedAgentId'] : null,
        );
    }
}
