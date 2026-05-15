<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Client;

/**
 * Abstraction over POSIX process signaling so AgentStopCommand and AgentResumeCommand can be tested
 * without spawning real processes.
 *
 * `signal($pid, 0)` is the canonical aliveness check and is delegated to `isAlive` for clarity.
 * Positive `$pid` targets a single process; negative `$pid` targets a process group, matching POSIX
 * `kill(2)` semantics.
 */
interface ProcessSignaler
{
    /**
     * Returns true when the given PID corresponds to a process this signaler can reach.
     */
    public function isAlive(int $pid): bool;

    /**
     * Sends the given signal to the given PID. Returns true on success, false otherwise.
     *
     * Pass a negative PID to target a process group.
     */
    public function signal(int $pid, int $signal): bool;
}
