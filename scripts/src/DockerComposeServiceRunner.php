<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

require_once __DIR__ . '/Application.php';

/**
 * Runs commands inside a specific Docker Compose service.
 */
final class DockerComposeServiceRunner
{
    public function __construct(
        private readonly Application $app,
        private readonly string $service,
    ) {
    }

    /**
     * Executes a command inside the configured service.
     *
     * @param list<string> $command
     */
    public function run(array $command, bool $tty = false): int
    {
        if ($command === []) {
            throw new \InvalidArgumentException('A Docker Compose command requires at least one argument.');
        }

        $execArgs = $tty ? '' : '-T ';
        $parts    = implode(' ', array_map('escapeshellarg', $command));

        return $this->app->runCommand(sprintf(
            'docker compose exec %s%s %s',
            $execArgs,
            escapeshellarg($this->service),
            $parts,
        ));
    }
}
