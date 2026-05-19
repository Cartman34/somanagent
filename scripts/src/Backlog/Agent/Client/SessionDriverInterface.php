<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Client;

use SoManAgent\Script\Backlog\Agent\Enum\AgentClient;
use SoManAgent\Script\Backlog\Agent\Enum\AgentRole;
use SoManAgent\Script\Backlog\Agent\Model\AgentSession;

/**
 * Abstracts the lifecycle of an AI agent session: launch, resume, stop, and liveness detection.
 *
 * Two implementations are provided:
 *   - TmuxSessionDriver: wraps each session in a named tmux session (somanagent-<code>). SSH-resilient.
 *     Requires the tmux binary. Enabled with BACKLOG_AGENT_SESSION_DRIVER=tmux (default).
 *   - DirectSessionDriver: spawns the client directly via proc_open. Degraded mode:
 *     no SSH resilience, resume reconnects the client CLI but not the live terminal session.
 *     Enabled with BACKLOG_AGENT_SESSION_DRIVER=direct.
 */
interface SessionDriverInterface
{
    /**
     * Validates that all dependencies required by this driver are available.
     *
     * @throws \RuntimeException with a user-readable diagnostic and install hint when a dependency is missing
     */
    public function checkDependencies(): void;

    /**
     * Returns true when the driver has an existing session for the given agent code.
     *
     * For TmuxSessionDriver: checks whether the tmux session somanagent-<code> already exists
     * by running `tmux has-session`; the result can change between calls as tmux state evolves.
     * For DirectSessionDriver: always returns false (proc_open has no persistent session concept).
     *
     * @param string $agentCode Agent code (e.g. d01)
     */
    public function sessionExists(string $agentCode): bool;

    /**
     * Returns true when resume is allowed while this driver still reports the session as alive.
     *
     * For TmuxSessionDriver: true, because an alive tmux session with a dead PHP wrapper means the
     * terminal was detached and resume should re-attach to the existing session.
     * For DirectSessionDriver: false, because an alive direct session means the client process is
     * still running and resume would start a second client instance.
     */
    public function allowsResumeWhileAlive(): bool;

    /**
     * Launches the AI client as an interactive session and blocks until exit.
     *
     * For TmuxSessionDriver: creates a named tmux session and attaches to it. Returns 0 whether
     * the session ended normally or the terminal was detached (Ctrl+B D, SSH disconnect).
     * Callers must call isAlive() with the refreshed AgentSession after this returns to distinguish
     * a detach (session still alive — do NOT remove the sessions.json entry) from a normal
     * termination (session gone — remove the entry).
     * For DirectSessionDriver: blocks until the client process exits; returns the client exit code.
     *
     * @param string $agentCode Agent code (e.g. d01); used to name the tmux session
     * @param AgentRole $role Role of the agent session; used for tmux branding
     * @param AgentClient $client AI client being launched; used for tmux branding
     * @param string $bin Absolute or PATH-resolvable client binary
     * @param list<string> $args Arguments for the client binary
     * @param string $cwd Working directory for the session
     * @param array<string, string> $env Full environment for the client process
     * @param callable(int $clientPid, ?string $tmuxSession): void $onSpawned Called once right after spawn, before blocking
     * @return int Exit code (0 on normal exit or tmux detach; client exit code for direct driver)
     */
    public function launch(string $agentCode, AgentRole $role, AgentClient $client, string $bin, array $args, string $cwd, array $env, callable $onSpawned): int;

    /**
     * Re-launches the AI client for an interrupted session and blocks until exit.
     *
     * For TmuxSessionDriver: re-attaches to the existing tmux session when alive; otherwise creates
     * a new session and launches the client in resume mode. Returns 0 whether the session ended
     * normally or was detached again. Callers must call isAlive() with the refreshed AgentSession
     * after return to distinguish detach (keep the sessions.json entry) from termination (remove it).
     * For DirectSessionDriver: same as launch() — no live terminal session to re-attach.
     *
     * @param string $agentCode Agent code (e.g. d01); used to locate or create the tmux session
     * @param AgentRole $role Role of the agent session; used for tmux branding when a new session is created
     * @param AgentClient $client AI client being launched; used for tmux branding when a new session is created
     * @param string $bin Absolute or PATH-resolvable client binary
     * @param list<string> $args Arguments for the client binary (resume flags already embedded)
     * @param string $cwd Working directory for the session
     * @param array<string, string> $env Full environment for the client process
     * @param callable(int $clientPid, ?string $tmuxSession): void $onSpawned Called once right after spawn, before blocking
     * @return int Exit code (0 on normal exit or tmux detach; client exit code for direct driver)
     */
    public function resume(string $agentCode, AgentRole $role, AgentClient $client, string $bin, array $args, string $cwd, array $env, callable $onSpawned): int;

    /**
     * Terminates the session tracked by the given AgentSession entry.
     *
     * For TmuxSessionDriver: kills the tmux session by name (tmux kill-session).
     * For DirectSessionDriver: sends SIGTERM to the recorded client PID (or wrapper PID),
     * waits up to the configured grace period, then follows up with SIGKILL.
     *
     * @throws \RuntimeException when the session cannot be stopped and cleanup fails
     */
    public function stop(AgentSession $session): void;

    /**
     * Returns true when the session associated with the given AgentSession entry is still alive.
     *
     * For TmuxSessionDriver: checks whether session->tmuxSession still exists via tmux has-session.
     * For DirectSessionDriver: checks session->clientPid (then session->pid) via ProcessSignaler.
     */
    public function isAlive(AgentSession $session): bool;

    /**
     * Lists all agent codes currently tracked by the driver as live sessions.
     *
     * Returns codes only (e.g. 'd11'), not full session names.
     * Used by prune to detect driver-side orphans — sessions alive in the driver but absent
     * from the registry.
     *
     * For TmuxSessionDriver: runs tmux list-sessions and filters on the somanagent- prefix.
     * For DirectSessionDriver: always returns [] (no persistent session concept).
     *
     * @return list<string>
     */
    public function listLiveSessions(): array;

    /**
     * Terminates the driver session for the given agent code without requiring an AgentSession entry.
     *
     * Used when a live driver session exists but the registry has no corresponding entry.
     *
     * For TmuxSessionDriver: kills the tmux session somanagent-<code> via tmux kill-session.
     * For DirectSessionDriver: no-op (proc_open has no persistent session concept).
     *
     * @param string $agentCode Agent code (e.g. d01)
     */
    public function kill(string $agentCode): void;
}
