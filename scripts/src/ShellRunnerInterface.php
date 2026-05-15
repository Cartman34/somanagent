<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script;

/**
 * Runs a shell command and returns its exit code.
 *
 * Separates command execution from the heavyweight Application singleton
 * so that classes that only need to run commands (not manage console I/O)
 * can be tested with a lightweight fake implementation.
 */
interface ShellRunnerInterface
{
    /**
     * Runs a shell command and returns the process exit code.
     */
    public function runCommand(string $cmd): int;
}
