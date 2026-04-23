<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Ticket;

use App\Exception\ValidationException;

/**
 * Input DTO for creating a comment on a ticket or ticket task.
 */
final class CreateCommentDto
{
    /**
     * @param string  $content      Comment content (trimmed)
     * @param ?string $replyToLogId Optional UUID of the log entry being replied to
     * @param ?string $context      Optional context identifier
     */
    public function __construct(
        public readonly string $content,
        public readonly ?string $replyToLogId,
        public readonly ?string $context,
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
            replyToLogId: isset($data['replyToLogId']) && $data['replyToLogId'] !== '' ? (string) $data['replyToLogId'] : null,
            context: isset($data['context']) && $data['context'] !== '' ? (string) $data['context'] : null,
        );
    }
}
