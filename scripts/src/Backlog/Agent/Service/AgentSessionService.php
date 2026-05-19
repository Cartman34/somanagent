<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Service;

use SoManAgent\Script\Backlog\Agent\Enum\AgentClient;
use SoManAgent\Script\Backlog\Agent\Enum\AgentRole;
use SoManAgent\Script\Backlog\Agent\Model\AgentSession;

/**
 * Reads and writes the agent sessions registry at local/tmp/agent-sessions.json (WP only).
 *
 * JSON key = agent code (unique). Fields: client, role, pid, worktree,
 * started_at, last_seen_at, session_id, client_pid, tmux_session.
 * Forbidden fields in JSON: feature, task, reviewing (always derived from backlog).
 *
 * Also appends one line per client launch to local/tmp/agent-launches.log for post-mortem
 * diagnostics. The log is never read by the workflow; it is append-only and not rotated.
 */
final class AgentSessionService
{
    private string $sessionsPath;
    private string $launchesLogPath;

    /**
     * @param string $projectRoot Absolute path to the main workspace (WP)
     */
    public function __construct(string $projectRoot)
    {
        $this->sessionsPath = $projectRoot . '/local/tmp/agent-sessions.json';
        $this->launchesLogPath = $projectRoot . '/local/tmp/agent-launches.log';
    }

    /**
     * @return array<string, AgentSession>
     */
    public function load(): array
    {
        if (!is_file($this->sessionsPath)) {
            return [];
        }

        $raw = file_get_contents($this->sessionsPath);
        if ($raw === false || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $sessions = [];
        foreach ($decoded as $code => $data) {
            if (!is_string($code) || !is_array($data)) {
                continue;
            }
            try {
                $sessions[$code] = AgentSession::fromArray($code, $data);
            } catch (\ValueError) {
                // skip malformed entries
            }
        }

        return $sessions;
    }

    /**
     * @param array<string, AgentSession> $sessions
     */
    public function save(array $sessions): void
    {
        $dir = dirname($this->sessionsPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $data = [];
        foreach ($sessions as $code => $session) {
            $data[$code] = $session->toArray();
        }

        file_put_contents(
            $this->sessionsPath,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );
    }

    /**
     * Returns the session for the given agent code, or null if absent.
     */
    public function get(string $code): ?AgentSession
    {
        return $this->load()[$code] ?? null;
    }

    /**
     * Returns true when a session entry exists for the given agent code.
     */
    public function has(string $code): bool
    {
        return $this->get($code) !== null;
    }

    /**
     * Adds or replaces the session entry for the given agent code.
     */
    public function add(AgentSession $session): void
    {
        $sessions = $this->load();
        $sessions[$session->code] = $session;
        $this->save($sessions);
    }

    /**
     * Removes the session entry for the given agent code.
     */
    public function remove(string $code): void
    {
        $sessions = $this->load();
        unset($sessions[$code]);
        $this->save($sessions);
    }

    /**
     * Updates the last_seen_at timestamp for the given agent code.
     */
    public function updateLastSeen(string $code): void
    {
        $sessions = $this->load();
        if (!isset($sessions[$code])) {
            return;
        }
        $sessions[$code] = $sessions[$code]->withLastSeenAt(new \DateTimeImmutable());
        $this->save($sessions);
    }

    /**
     * Updates the session_id for the given agent code.
     */
    public function updateSessionId(string $code, ?string $sessionId): void
    {
        $sessions = $this->load();
        if (!isset($sessions[$code])) {
            return;
        }
        $sessions[$code] = $sessions[$code]->withSessionId($sessionId);
        $this->save($sessions);
    }

    /**
     * Updates the recorded worktree path for the given agent code.
     */
    public function updateWorktree(string $code, string $worktree): void
    {
        $sessions = $this->load();
        if (!isset($sessions[$code])) {
            return;
        }
        $session = $sessions[$code];
        $sessions[$code] = new AgentSession(
            code: $session->code,
            client: $session->client,
            role: $session->role,
            pid: $session->pid,
            worktree: $worktree,
            startedAt: $session->startedAt,
            lastSeenAt: $session->lastSeenAt,
            sessionId: $session->sessionId,
            clientPid: $session->clientPid,
            tmuxSession: $session->tmuxSession,
        );
        $this->save($sessions);
    }

    /**
     * Updates the recorded client PID for the given agent code.
     *
     * Called right after the launcher spawns the actual client process so that `stop` can
     * target the real client rather than only the PHP wrapper.
     */
    public function updateClientPid(string $code, ?int $clientPid): void
    {
        $sessions = $this->load();
        if (!isset($sessions[$code])) {
            return;
        }
        $sessions[$code] = $sessions[$code]->withClientPid($clientPid);
        $this->save($sessions);
    }

    /**
     * Updates the tmux session name for the given agent code.
     *
     * Set to the tmux session name (e.g. somanagent-d01) when driver=tmux, or null when driver=direct.
     */
    public function updateTmuxSession(string $code, ?string $tmuxSession): void
    {
        $sessions = $this->load();
        if (!isset($sessions[$code])) {
            return;
        }
        $sessions[$code] = $sessions[$code]->withTmuxSession($tmuxSession);
        $this->save($sessions);
    }

    /**
     * Creates a new session entry from raw parameters.
     */
    public function create(
        string $code,
        AgentClient $client,
        AgentRole $role,
        int $pid,
        string $worktree,
    ): AgentSession {
        $now = new \DateTimeImmutable();
        $session = new AgentSession(
            code: $code,
            client: $client,
            role: $role,
            pid: $pid,
            worktree: $worktree,
            startedAt: $now,
            lastSeenAt: $now,
            sessionId: null,
        );
        $this->add($session);

        return $session;
    }

    /**
     * Appends one line to the launch log at local/tmp/agent-launches.log.
     *
     * Called once per client launch (start or resume), right after the driver reports the
     * client PID via the onSpawned callback. The log is append-only, never read by the
     * workflow, and serves exclusively for post-mortem diagnostics (e.g. verifying which
     * flags were passed to a client for a past session).
     *
     * Line format (tab-separated):
     *   timestamp ISO 8601 | agent code | role | client | driver | full command line | client PID
     *
     * @param list<string> $args
     */
    public function logLaunch(
        string $code,
        AgentRole $role,
        AgentClient $client,
        string $driver,
        string $bin,
        array $args,
        int $clientPid,
    ): void {
        $dir = dirname($this->launchesLogPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $timestamp = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $cmdLine = implode(' ', array_map('escapeshellarg', [$bin, ...$args]));
        $line = implode("\t", [$timestamp, $code, $role->value, $client->value, $driver, $cmdLine, (string) $clientPid]) . "\n";

        file_put_contents($this->launchesLogPath, $line, FILE_APPEND | LOCK_EX);
    }
}
