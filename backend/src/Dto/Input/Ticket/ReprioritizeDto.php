<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Ticket;

use App\Enum\TaskPriority;
use App\Exception\ValidationException;

/**
 * Input DTO for reprioritizing a ticket or ticket task.
 */
final class ReprioritizeDto
{
    /**
     * @param TaskPriority $priority New priority value
     */
    public function __construct(
        public readonly TaskPriority $priority,
    ) {}

    /**
     * @throws ValidationException with accumulated validation errors
     */
    public static function fromArray(array $data): self
    {
        $errors = [];

        if (empty($data['priority'])) {
            $errors[] = ['field' => 'priority', 'code' => 'ticket.validation.priority_required'];
        } else {
            $priority = TaskPriority::tryFrom((string) $data['priority']);
            if ($priority === null) {
                $errors[] = ['field' => 'priority', 'code' => 'ticket.validation.priority_invalid'];
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new self(
            priority: $priority,
        );
    }
}
