<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Agent\Client;

/**
 * Runs small local process checks needed by concrete agent launchers.
 */
interface ProcessRunner
{
    /**
     * Returns true when the command exits with code 0.
     */
    public function succeeds(string $command): bool;

    /**
     * Runs a command in the given working directory and returns stdout, or null on failure.
     */
    public function output(string $command, string $cwd = ''): ?string;
}
