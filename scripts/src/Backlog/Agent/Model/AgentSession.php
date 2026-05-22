<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Model;

use SoManAgent\Script\Backlog\Agent\Client\ProcessSignaler;
use SoManAgent\Script\Backlog\Agent\Enum\AgentClient;
use SoManAgent\Script\Backlog\Agent\Enum\AgentRole;
use SoManAgent\Script\Backlog\Enum\SubmitMode;

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
     * @param string|null $tmuxSession Name of the tmux session (e.g. somanagent-d01) when driver=tmux; null when driver=direct
     * @param bool|null $reviewResume Whether the review-resume notification is on (true), off (false), or default/absent (null)
     * @param SubmitMode|null $submitMode Per-session submit policy override (null = use project config or fallback)
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
        public readonly ?string $tmuxSession = null,
        public readonly ?bool $reviewResume = null,
        public readonly ?SubmitMode $submitMode = null,
    ) {}

    /**
     * Returns true when at least one tracked process (client, then wrapper) is alive.
     *
     * The client process is checked first because the wrapper may exit while the client is still running
     * (typical race when the wrapper crashes mid-session). When client_pid is null the check falls back
     * to the wrapper pid.
     */
    public function isAlive(ProcessSignaler $signaler): bool
    {
        if ($this->clientPid !== null && $this->clientPid > 0 && $signaler->isAlive($this->clientPid)) {
            return true;
        }

        if ($this->pid <= 0) {
            return false;
        }

        return $signaler->isAlive($this->pid);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'client' => $this->client->value,
            'role' => $this->role->value,
            'pid' => $this->pid,
            'client_pid' => $this->clientPid,
            'tmux_session' => $this->tmuxSession,
            'worktree' => $this->worktree,
            'started_at' => $this->startedAt->format(\DateTimeInterface::ATOM),
            'last_seen_at' => $this->lastSeenAt->format(\DateTimeInterface::ATOM),
            'session_id' => $this->sessionId,
        ];

        if ($this->reviewResume !== null) {
            $data['review_resume'] = $this->reviewResume;
        }

        if ($this->submitMode !== null) {
            $data['submit_mode'] = $this->submitMode->value;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(string $code, array $data): self
    {
        $reviewResume = $data['review_resume'] ?? null;
        $submitModeRaw = isset($data['submit_mode']) ? (string) $data['submit_mode'] : null;

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
            tmuxSession: isset($data['tmux_session']) ? (string) $data['tmux_session'] : null,
            reviewResume: is_bool($reviewResume) ? $reviewResume : null,
            submitMode: $submitModeRaw !== null ? SubmitMode::tryFrom($submitModeRaw) : null,
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
            tmuxSession: $this->tmuxSession,
            reviewResume: $this->reviewResume,
            submitMode: $this->submitMode,
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
            tmuxSession: $this->tmuxSession,
            reviewResume: $this->reviewResume,
            submitMode: $this->submitMode,
        );
    }

    /**
     * Returns a copy of this session with the client PID updated.
     */
    public function withClientPid(?int $clientPid): self
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
            tmuxSession: $this->tmuxSession,
            reviewResume: $this->reviewResume,
            submitMode: $this->submitMode,
        );
    }

    /**
     * Returns a copy of this session with the tmux session name updated.
     */
    public function withTmuxSession(?string $tmuxSession): self
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
            clientPid: $this->clientPid,
            tmuxSession: $tmuxSession,
            reviewResume: $this->reviewResume,
            submitMode: $this->submitMode,
        );
    }

}

