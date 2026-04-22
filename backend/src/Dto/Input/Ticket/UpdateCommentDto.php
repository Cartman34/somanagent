<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Ticket;

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
     * @throws \InvalidArgumentException with a short domain code on validation failure
     */
    public static function fromArray(array $data): self
    {
        $content = trim((string) ($data['content'] ?? ''));
        if ($content === '') {
            throw new \InvalidArgumentException('content_required');
        }

        return new self(
            content: $content,
        );
    }
}
