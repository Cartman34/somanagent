<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Client;

use SoManAgent\Script\Backlog\Agent\Enum\AgentClient;
use SoManAgent\Script\Backlog\Agent\Enum\AgentRole;
use SoManAgent\Script\Backlog\Agent\Model\ResolvedModel;
use SoManAgent\Script\Backlog\Agent\Model\SessionInfo;

/**
 * Contract for all AI coding client launchers used by backlog-agent.php.
 *
 * Each concrete implementation covers one CLI tool (Claude, Codex, OpenCode, Gemini)
 * and handles the specifics of context injection, resume, and session discovery for
 * that tool. The framework in AgentStartCommand / AgentResumeCommand calls these
 * hooks in a fixed order defined by §7.0.1 of the backlog-agent spec.
 */
interface AgentClientLauncher
{
    /**
     * Identifies which client this launcher targets.
     */
    public function client(): AgentClient;

    /**
     * Returns true when the CLI binary is available in PATH.
     *
     * The framework calls this before launching. When false, throw
     * ClientNotInstalledException with a remediation hint.
     */
    public function isAvailable(): bool;

    /**
     * Idempotent worktree preparation before launch.
     *
     * Override to write or merge a per-client config file inside the worktree.
     * Default implementation is a no-op.
     */
    public function prepareWorktree(string $worktree, string $contextFilePath): void;

    /**
     * Returns the environment to pass to the CLI process.
     *
     * Receives base env already populated with SOMANAGER_AGENT/ROLE/CLIENT/WP
     * plus inherited shell env. Default implementation returns base env unchanged.
     *
     * @param array<string, string> $baseEnv
     * @return array<string, string>
     */
    public function buildEnvironment(array $baseEnv, string $contextFilePath): array;

    /**
     * Builds the shell command [binary, args] to launch the CLI.
     *
     * Three modes — exactly one is in effect:
     *   - initial start: $resumeSessionId === null && !$continueLast
     *   - resume last:   $continueLast === true
     *   - resume specific: $resumeSessionId !== null
     *
     * The framework chdir()s into $worktree before exec.
     *
     * @param ResolvedModel|null $resolvedModel Optional start-only model args resolved from role defaults and CLI overrides
     * @param string|null $initialPrompt Optional start-only user prompt sent after an automatic backlog pick
     * @return array{0: string, 1: list<string>}
     */
    public function buildLaunchCommand(
        string $worktree,
        string $contextFilePath,
        AgentRole $role,
        ?string $resumeSessionId = null,
        bool $continueLast = false,
        ?ResolvedModel $resolvedModel = null,
        ?string $initialPrompt = null,
    ): array;

    /**
     * Best-effort: returns the most-recent session id for $worktree, or null if unavailable.
     *
     * Called after a launch returns to update sessions.json with the actual id.
     */
    public function captureCurrentSessionId(string $worktree): ?string;

    /**
     * Lists past sessions for $worktree, sorted by recency desc.
     *
     * @return list<SessionInfo>
     */
    public function listSessions(string $worktree): array;

    /**
     * Returns the CLI option flags that buildLaunchCommand() relies on for this client.
     *
     * Each entry is the flag spelling exactly as it appears on the command line
     * (for example `--append-system-prompt`, `--resume`, `-C`, `-s`). Only short
     * or long option flags are listed; subcommand names and positional arguments
     * are not included. Used by `scripts/validate-agent-launchers.php` to detect
     * upstream CLI removals before they break a real launch.
     *
     * @return list<string>
     */
    public function requiredCliFlags(): array;
}
