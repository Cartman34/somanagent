<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\DevEnv;

/**
 * Runs system commands and returns their stdout.
 *
 * Used by StateInspector to detect installed package versions without
 * coupling it directly to shell_exec.
 */
interface CommandRunnerInterface
{
    /**
     * Runs the given command and returns its stdout, or null when the command fails.
     */
    public function output(string $command): ?string;
}
