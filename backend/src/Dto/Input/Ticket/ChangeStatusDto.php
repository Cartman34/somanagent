<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Ticket;

use App\Enum\TaskStatus;
use App\Exception\ValidationException;

/**
 * Input DTO for changing the status of a ticket or ticket task.
 */
final class ChangeStatusDto
{
    /**
     * @param TaskStatus $status New status value
     */
    public function __construct(
        public readonly TaskStatus $status,
    ) {}

    /**
     * @throws ValidationException with accumulated validation errors
     */
    public static function fromArray(array $data): self
    {
        $errors = [];

        if (empty($data['status'])) {
            $errors[] = ['field' => 'status', 'code' => 'ticket.validation.status_required'];
        } else {
            $status = TaskStatus::tryFrom((string) $data['status']);
            if ($status === null) {
                $errors[] = ['field' => 'status', 'code' => 'ticket.validation.status_invalid'];
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new self(
            status: $status,
        );
    }
}
