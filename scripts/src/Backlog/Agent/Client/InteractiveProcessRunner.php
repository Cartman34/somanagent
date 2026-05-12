<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Client;

/**
 * Launches an AI client as an interactive child process attached to the current terminal.
 *
 * Implementations must:
 *   - keep STDIN/STDOUT/STDERR attached to the controlling terminal so the user keeps a normal CLI chat,
 *   - expose the actual client PID and process group via the result so `stop` can terminate the client,
 *   - block until the child exits and return its exit code.
 *
 * `$onSpawned` is invoked once, right after the child becomes visible and before waiting, so the caller
 * can persist the client PID and process group into agent-sessions.json without racing the child exit.
 */
interface InteractiveProcessRunner
{
    /**
     * @param string $bin Absolute or PATH-resolvable client binary
     * @param list<string> $args Arguments passed to the binary (no shell expansion)
     * @param string $cwd Working directory for the child process
     * @param array<string, string> $env Full environment passed to the child
     * @param (callable(int $clientPid, ?int $processGroupId): void)|null $onSpawned Called once after spawn, before wait
     */
    public function run(string $bin, array $args, string $cwd, array $env, ?callable $onSpawned = null): InteractiveProcessResult;
}
