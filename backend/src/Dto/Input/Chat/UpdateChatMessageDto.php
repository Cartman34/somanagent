<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Chat;

/**
 * Input DTO for editing a chat message.
 */
final class UpdateChatMessageDto
{
    /**
     * @param string $content Updated message content (trimmed)
     */
    public function __construct(
        public readonly string $content,
    ) {}

    /**
     * @throws \InvalidArgumentException with a short domain code on validation failure
     */
    public static function fromArray(array $data): self
    {
        if (empty($data['content'])) {
            throw new \InvalidArgumentException('content_required');
        }

        return new self(
            content: trim((string) $data['content']),
        );
    }
}
