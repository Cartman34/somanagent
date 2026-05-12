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
     * @param int $pid PID of the PHP wrapper process (kept for diagnostics and stale-wrapper detection)
     * @param string $worktree Absolute path to the agent worktree
     * @param \DateTimeImmutable $startedAt When the session was started
     * @param \DateTimeImmutable $lastSeenAt Last time a backlog-agent subcommand observed this entry and refreshed its PID/process status
     * @param string|null $sessionId Optional client-specific session identifier
     * @param int|null $clientPid Actual AI client process PID when known; null when the launcher cannot determine it
     * @param int|null $processGroupId Process group to terminate on stop; required when the client is launched through an intermediate shell or wrapper
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
        public readonly ?int $clientPid = null,
        public readonly ?int $processGroupId = null,
    ) {}

    /**
     * Returns true when at least one tracked process (client, then wrapper) is alive.
     *
     * The client process is checked first because the wrapper may exit while the client is still running
     * (typical race when the wrapper crashes mid-session). When client_pid is null the check falls back
     * to the wrapper pid.
     */
    public function isAlive(): bool
    {
        if ($this->clientPid !== null && $this->clientPid > 0 && posix_kill($this->clientPid, 0) !== false) {
            return true;
        }

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
            'client_pid' => $this->clientPid,
            'process_group_id' => $this->processGroupId,
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
            clientPid: isset($data['client_pid']) ? (int) $data['client_pid'] : null,
            processGroupId: isset($data['process_group_id']) ? (int) $data['process_group_id'] : null,
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
            clientPid: $this->clientPid,
            processGroupId: $this->processGroupId,
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
            clientPid: $this->clientPid,
            processGroupId: $this->processGroupId,
        );
    }

    /**
     * Returns a copy of this session with the client process identifiers recorded.
     */
    public function withClientProcess(?int $clientPid, ?int $processGroupId): self
    {
        return new self(
            code: $this->code,
            client: $this->client,
            role: $this->role,
            pid: $this->pid,
            worktree: $this->worktree,
            startedAt: $this->startedAt,
            lastSeenAt: $this->lastSeenAt,
            sessionId: $this->sessionId,
            clientPid: $clientPid,
            processGroupId: $processGroupId,
        );
    }
}
