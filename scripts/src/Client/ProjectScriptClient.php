<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Client;

/**
 * Executes project PHP script entrypoints through stable script references.
 *
 * Maintenance rules:
 * - Keep this client limited to shared script-launch mechanics.
 * - Add a new AppScript case only when the script path is stable and reused.
 * - Keep script-specific business logic in dedicated clients/services instead of here.
 * - Do not duplicate higher-level option building that belongs to a domain client.
 */
final class ProjectScriptClient
{
    private ConsoleClient $console;

    public function __construct(ConsoleClient $console)
    {
        $this->console = $console;
    }

    public function run(AppScript $script, string $arguments = '', ?string $projectRoot = null): void
    {
        $this->console->run($this->command($script, $arguments, $projectRoot));
    }

    public function capture(AppScript $script, string $arguments = '', ?string $projectRoot = null): string
    {
        return $this->console->capture($this->command($script, $arguments, $projectRoot));
    }

    public function command(AppScript $script, string $arguments = '', ?string $projectRoot = null): string
    {
        $scriptPath = $projectRoot === null
            ? $script->value
            : $this->console->toRelativeProjectPath(rtrim($projectRoot, '/') . '/' . $script->value);

        return trim(sprintf('php %s %s', escapeshellarg($scriptPath), trim($arguments)));
    }
}
