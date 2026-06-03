<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Runner;

use Sowapps\SoManAgent\Script\Application;

/**
 * Runs commands inside a specific Docker Compose service.
 */
final class DockerComposeServiceRunner
{
    /**
     * @param Application $app
     * @param string $service Docker Compose service name (e.g. "php", "db")
     */
    public function __construct(
        private readonly Application $app,
        private readonly string $service,
    ) {
    }

    /**
     * Executes a command inside the configured service.
     *
     * @param list<string> $command
     * @param bool $tty
     * @param array<string, string> $env Extra environment variables passed with -e KEY=VALUE
     */
    public function run(array $command, bool $tty = false, array $env = []): int
    {
        if ($command === []) {
            throw new \InvalidArgumentException('A Docker Compose command requires at least one argument.');
        }

        $execArgs = $tty ? '' : '-T ';

        $envArgs = '';
        foreach ($env as $key => $val) {
            $envArgs .= '-e ' . escapeshellarg($key . '=' . $val) . ' ';
        }

        $parts = implode(' ', array_map('escapeshellarg', $command));

        return $this->app->runCommand(sprintf(
            'docker compose exec %s%s%s %s',
            $execArgs,
            $envArgs,
            escapeshellarg($this->service),
            $parts,
        ));
    }
}
