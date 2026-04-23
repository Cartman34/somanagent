<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Chat;

use App\Exception\ValidationException;

/**
 * Input DTO for replying to a chat message.
 */
final class ReplyChatMessageDto
{
    /**
     * @param string $content          Reply content (trimmed)
     * @param string $replyToMessageId UUID of the message being replied to
     */
    public function __construct(
        public readonly string $content,
        public readonly string $replyToMessageId,
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

        if (empty($data['replyToMessageId'])) {
            $errors[] = ['field' => 'replyToMessageId', 'code' => 'chat.validation.reply_to_required'];
        }

        if ($errors) {
            throw new ValidationException($errors);
        }

        return new self(
            content: trim((string) $data['content']),
            replyToMessageId: (string) $data['replyToMessageId'],
        );
    }
}
