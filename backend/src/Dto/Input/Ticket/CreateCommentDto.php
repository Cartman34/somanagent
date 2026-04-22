<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Ticket;

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
            replyToLogId: isset($data['replyToLogId']) && $data['replyToLogId'] !== '' ? (string) $data['replyToLogId'] : null,
            context: isset($data['context']) && $data['context'] !== '' ? (string) $data['context'] : null,
        );
    }
}
