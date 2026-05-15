<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Client;

/**
 * Real ProcessSignaler backed by posix_kill.
 */
final class PosixProcessSignaler implements ProcessSignaler
{
    /**
     * {@inheritdoc}
     */
    public function isAlive(int $pid): bool
    {
        if ($pid === 0) {
            return false;
        }

        return posix_kill($pid, 0);
    }

    /**
     * {@inheritdoc}
     */
    public function signal(int $pid, int $signal): bool
    {
        if ($pid === 0) {
            return false;
        }

        return posix_kill($pid, $signal);
    }
}
