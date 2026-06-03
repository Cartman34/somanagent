<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Agent\Model;

/**
 * Summarises one past CLI session for a worktree, returned by AgentClientLauncher::listSessions().
 */
final class SessionInfo
{
    /**
     * @param string $id Client-specific unique session identifier
     * @param \DateTimeImmutable|null $startedAt When the session started
     * @param \DateTimeImmutable|null $lastMessageAt Timestamp of the last message
     * @param int|null $messageCount Total number of messages exchanged
     * @param string|null $firstPromptExcerpt Excerpt of the first user prompt
     */
    public function __construct(
        public readonly string $id,
        public readonly ?\DateTimeImmutable $startedAt,
        public readonly ?\DateTimeImmutable $lastMessageAt,
        public readonly ?int $messageCount,
        public readonly ?string $firstPromptExcerpt,
    ) {}
}
