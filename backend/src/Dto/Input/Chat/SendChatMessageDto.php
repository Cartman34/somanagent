<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Chat;

use App\Exception\ValidationException;

/**
 * Input DTO for sending a chat message.
 */
final class SendChatMessageDto
{
    /**
     * @param string $content Message content (trimmed)
     */
    public function __construct(
        public readonly string $content,
    ) {}

    /**
     * @throws ValidationException with validation errors
     */
    public static function fromArray(array $data): self
    {
        $errors = [];

        if (empty($data['content'])) {
            $errors[] = ['field' => 'content', 'code' => 'chat.validation.content_required'];
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new self(
            content: trim((string) $data['content']),
        );
    }
}
