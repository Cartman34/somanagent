<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Chat;

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
     * @throws \InvalidArgumentException with a short domain code on validation failure
     */
    public static function fromArray(array $data): self
    {
        if (empty($data['content'])) {
            throw new \InvalidArgumentException('content_required');
        }

        if (empty($data['replyToMessageId'])) {
            throw new \InvalidArgumentException('reply_to_required');
        }

        return new self(
            content: trim((string) $data['content']),
            replyToMessageId: (string) $data['replyToMessageId'],
        );
    }
}
