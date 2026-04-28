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

    /**
     * Creates the project script execution client.
     */
    public function __construct(ConsoleClient $console)
    {
        $this->console = $console;
    }

    /**
     * Executes one known project script and throws on failure.
     */
    public function run(AppScript $script, string $arguments = '', ?string $projectRoot = null): void
    {
        $this->console->run($this->command($script, $arguments, $projectRoot));
    }

    /**
     * Executes one known project script and returns its captured output.
     */
    public function capture(AppScript $script, string $arguments = '', ?string $projectRoot = null): string
    {
        return $this->console->capture($this->command($script, $arguments, $projectRoot));
    }

    /**
     * @return array{0: int, 1: string}
     */
    public function captureWithExitCode(AppScript $script, string $arguments = '', ?string $projectRoot = null): array
    {
        return $this->console->captureWithExitCode($this->command($script, $arguments, $projectRoot));
    }

    /**
     * Builds the shell command used to execute one known project script.
     */
    public function command(AppScript $script, string $arguments = '', ?string $projectRoot = null): string
    {
        if ($projectRoot !== null) {
            return trim(sprintf(
                'cd %s && php %s %s',
                escapeshellarg($this->console->toRelativeProjectPath($projectRoot)),
                escapeshellarg($script->value),
                trim($arguments),
            ));
        }

        return trim(sprintf('php %s %s', escapeshellarg($script->value), trim($arguments)));
    }
}
