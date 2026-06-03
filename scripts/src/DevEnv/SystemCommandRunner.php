<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\DevEnv;

use Sowapps\SoManAgent\Script\DevEnv\CommandRunnerInterface;

/**
 * Production implementation of CommandRunnerInterface that runs real shell commands.
 *
 * Uses shell_exec internally — commands must include any required redirections
 * (e.g. 2>/dev/null) when stderr should be suppressed.
 */
final class SystemCommandRunner implements CommandRunnerInterface
{
    /**
     * {@inheritdoc}
     */
    public function output(string $command): ?string
    {
        $result = shell_exec($command);

        return is_string($result) ? $result : null;
    }
}
