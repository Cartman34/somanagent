<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Model;

use SoManAgent\Script\Backlog\Agent\Enum\AgentClient;
use SoManAgent\Script\Backlog\Agent\Enum\AgentRole;

/**
 * Represents one live or stale agent session entry from agent-sessions.json.
 */
final class AgentSession
{
    /**
     * @param string $code Agent code (e.g. d01, r02, m03)
     * @param AgentClient $client AI client that was launched
     * @param AgentRole $role Role of the session
     * @param int $pid PID of the launched process
     * @param string $worktree Absolute path to the agent worktree
     * @param \DateTimeImmutable $startedAt When the session was started
     * @param \DateTimeImmutable $lastSeenAt When the process was last confirmed alive
     * @param string|null $sessionId Optional client-specific session identifier
     */
    public function __construct(
        public readonly string $code,
        public readonly AgentClient $client,
        public readonly AgentRole $role,
        public readonly int $pid,
        public readonly string $worktree,
        public readonly \DateTimeImmutable $startedAt,
        public readonly \DateTimeImmutable $lastSeenAt,
        public readonly ?string $sessionId,
    ) {}

    /**
     * Returns true when the PID corresponds to a running process.
     */
    public function isAlive(): bool
    {
        if ($this->pid <= 0) {
            return false;
        }

        return posix_kill($this->pid, 0) !== false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'client' => $this->client->value,
            'role' => $this->role->value,
            'pid' => $this->pid,
            'worktree' => $this->worktree,
            'started_at' => $this->startedAt->format(\DateTimeInterface::ATOM),
            'last_seen_at' => $this->lastSeenAt->format(\DateTimeInterface::ATOM),
            'session_id' => $this->sessionId,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(string $code, array $data): self
    {
        return new self(
            code: $code,
            client: AgentClient::from((string) ($data['client'] ?? '')),
            role: AgentRole::from((string) ($data['role'] ?? '')),
            pid: (int) ($data['pid'] ?? 0),
            worktree: (string) ($data['worktree'] ?? ''),
            startedAt: new \DateTimeImmutable((string) ($data['started_at'] ?? 'now')),
            lastSeenAt: new \DateTimeImmutable((string) ($data['last_seen_at'] ?? 'now')),
            sessionId: isset($data['session_id']) ? (string) $data['session_id'] : null,
        );
    }

    /**
     * Returns a copy of this session with the last_seen_at timestamp updated.
     */
    public function withLastSeenAt(\DateTimeImmutable $lastSeenAt): self
    {
        return new self(
            code: $this->code,
            client: $this->client,
            role: $this->role,
            pid: $this->pid,
            worktree: $this->worktree,
            startedAt: $this->startedAt,
            lastSeenAt: $lastSeenAt,
            sessionId: $this->sessionId,
        );
    }

    /**
     * Returns a copy of this session with the session_id updated.
     */
    public function withSessionId(?string $sessionId): self
    {
        return new self(
            code: $this->code,
            client: $this->client,
            role: $this->role,
            pid: $this->pid,
            worktree: $this->worktree,
            startedAt: $this->startedAt,
            lastSeenAt: $this->lastSeenAt,
            sessionId: $sessionId,
        );
    }
}
