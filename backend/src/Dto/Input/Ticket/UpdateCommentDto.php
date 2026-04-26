<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Ticket;

use App\Exception\ValidationException;

/**
 * Input DTO for editing an existing comment.
 */
final class UpdateCommentDto
{
    /**
     * @param string $content Updated comment content (trimmed)
     */
    public function __construct(
        public readonly string $content,
    ) {}

    /**
     * @throws ValidationException with accumulated validation errors
     */
    public static function fromArray(array $data): self
    {
        $errors = [];
        $content = trim((string) ($data['content'] ?? ''));

        if ($content === '') {
            $errors[] = ['field' => 'content', 'code' => 'ticket.validation.content_required'];
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new self(
            content: $content,
        );
    }
}
