<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Agent\Test;

use Sowapps\SoManAgent\Script\Backlog\Agent\Client\ProcessSignaler;

/**
 * In-memory ProcessSignaler used by command tests.
 *
 * Tracks the sequence of signal() calls for assertions and exposes hooks to simulate processes that
 * exit (or do not exit) in response to SIGTERM.
 */
final class FakeProcessSignaler implements ProcessSignaler
{
    /** @var array<int, bool> */
    private array $alive = [];

    /** @var list<array{pid: int, signal: int}> */
    public array $signals = [];

    /**
     * When true, SIGTERM causes the targeted PID to become dead. Used to simulate well-behaved clients.
     * When false, only SIGKILL marks PIDs as dead. Used to simulate stuck clients.
     */
    public bool $sigtermKills = true;

    /**
     * Marks the given PID as alive or dead for subsequent isAlive() calls.
     */
    public function setAlive(int $pid, bool $alive): void
    {
        $this->alive[$pid] = $alive;
    }

    /**
     * {@inheritdoc}
     */
    public function isAlive(int $pid): bool
    {
        // Process-group queries (negative pids) check the corresponding positive pid for aliveness.
        $key = abs($pid);

        return $this->alive[$key] ?? false;
    }

    /**
     * {@inheritdoc}
     */
    public function signal(int $pid, int $signal): bool
    {
        $this->signals[] = ['pid' => $pid, 'signal' => $signal];

        if ($signal === SIGKILL || ($signal === SIGTERM && $this->sigtermKills)) {
            $this->alive[abs($pid)] = false;
        }

        return true;
    }
}
