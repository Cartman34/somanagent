<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Agent\Client;

use Sowapps\SoManAgent\Script\Backlog\Agent\Client\ProcessRunner;

/**
 * ProcessRunner implementation backed by the local shell.
 */
final class ShellProcessRunner implements ProcessRunner
{
    /**
     * {@inheritdoc}
     */
    public function succeeds(string $command): bool
    {
        $output = [];
        $code = 0;
        exec($command . ' 2>&1', $output, $code);

        return $code === 0;
    }

    /**
     * {@inheritdoc}
     */
    public function output(string $command, string $cwd = ''): ?string
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $proc = proc_open($command, $descriptors, $pipes, $cwd !== '' ? $cwd : null);
        if (!is_resource($proc)) {
            return null;
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        return is_string($stdout) ? $stdout : null;
    }
}
