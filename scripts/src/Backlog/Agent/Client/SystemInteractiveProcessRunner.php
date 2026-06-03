<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Agent\Client;

use Sowapps\SoManAgent\Script\Backlog\Agent\Client\InteractiveProcessRunner;
use Sowapps\SoManAgent\Script\Backlog\Agent\Client\InteractiveProcessResult;

/**
 * Real InteractiveProcessRunner implementation backed by proc_open.
 *
 * Uses the array form of proc_open so no shell is interposed between PHP and the client. STDIN, STDOUT
 * and STDERR are forwarded to the current terminal so the user keeps a normal interactive chat. The
 * spawned child is therefore the actual client process: proc_get_status returns its real PID, which
 * gives `stop` something concrete to signal.
 */
final class SystemInteractiveProcessRunner implements InteractiveProcessRunner
{
    private const POLL_USEC = 200_000;

    /**
     * {@inheritdoc}
     */
    public function run(string $bin, array $args, string $cwd, array $env, ?callable $onSpawned = null): InteractiveProcessResult
    {
        $descriptors = [
            0 => STDIN,
            1 => STDOUT,
            2 => STDERR,
        ];

        $command = array_merge([$bin], $args);
        $pipes = [];

        $process = proc_open($command, $descriptors, $pipes, $cwd !== '' ? $cwd : null, $env);
        if (!is_resource($process)) {
            throw new \RuntimeException(sprintf('Failed to spawn interactive process: %s', $bin));
        }

        $status = proc_get_status($process);
        $clientPid = $status['pid'];

        if ($onSpawned !== null) {
            $onSpawned($clientPid);
        }

        $exitCode = $this->waitForExit($process);
        proc_close($process);

        return new InteractiveProcessResult($exitCode, $clientPid > 0 ? $clientPid : null);
    }

    /**
     * Polls proc_get_status until the process exits and returns its exit code.
     *
     * @param resource $process
     */
    private function waitForExit($process): int
    {
        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                return (int) $status['exitcode'];
            }
            usleep(self::POLL_USEC);
        }
    }
}
