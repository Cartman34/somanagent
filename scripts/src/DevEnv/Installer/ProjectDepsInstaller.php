<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\DevEnv\Installer;

use SoManAgent\Script\Application;
use SoManAgent\Script\Console;

/**
 * Runs project-level setup steps inside running Docker containers.
 *
 * Not lockfile-driven — these steps are post-install project bootstrapping
 * that require the containers to be running:
 *   1. composer install (in the php container)
 *   2. npm install (in the node container)
 *   3. Doctrine migrations (if db is healthy)
 *
 * All steps are conditional: skipped with a warning if the target container
 * is not running. This makes install safe to run before `server.php start`.
 */
final class ProjectDepsInstaller
{
    /**
     * @param Application $app     Command runner
     * @param Console     $console Output helper
     */
    public function __construct(
        private readonly Application $app,
        private readonly Console $console,
    ) {
    }

    /**
     * Returns true when this installer has nothing to do for a given PlannedDep.
     *
     * ProjectDepsInstaller is not driven by lockfile entries; use runProjectSteps() directly.
     */
    public function supports(\SoManAgent\Script\DevEnv\PlannedDep $dep): bool
    {
        return false;
    }

    /**
     * Returns simulated commands for the project-level setup steps.
     *
     * @return list<string>
     */
    public function getSimulatedCommands(): array
    {
        return [
            'docker compose exec -T php composer install',
            'docker compose exec -T node npm install',
            'docker compose exec -T php php bin/console doctrine:migrations:migrate --no-interaction',
        ];
    }

    /**
     * Runs project-level setup steps in the running containers.
     *
     * Each step is skipped if its target container is not running, with a
     * warning message directing the user to run `server.php start` first.
     */
    public function runProjectSteps(): void
    {
        $this->console->step('Project setup steps');

        if ($this->isContainerRunning('somanagent_php')) {
            $this->console->info('Running composer install...');
            $code = $this->app->runCommand('docker compose exec -T php composer install');
            if ($code !== 0) {
                throw new \RuntimeException(sprintf('composer install failed (exit %d)', $code));
            }
            $this->console->ok('composer install done.');
        } else {
            $this->console->warn('php container not running — skipping composer install. Run: php scripts/server.php start');
        }

        if ($this->isContainerRunning('somanagent_node')) {
            $this->console->info('Running npm install...');
            $code = $this->app->runCommand('docker compose exec -T node npm install');
            if ($code !== 0) {
                throw new \RuntimeException(sprintf('npm install failed (exit %d)', $code));
            }
            $this->console->ok('npm install done.');
        } else {
            $this->console->warn('node container not running — skipping npm install. Run: php scripts/server.php start');
        }

        if ($this->isContainerRunning('somanagent_db')) {
            $this->console->info('Running Doctrine migrations...');
            $code = $this->app->runCommand(
                'docker compose exec -T php php bin/console doctrine:migrations:migrate --no-interaction',
            );
            if ($code !== 0) {
                throw new \RuntimeException(sprintf('Doctrine migrations failed (exit %d)', $code));
            }
            $this->console->ok('Migrations complete.');
        } else {
            $this->console->warn('db container not running — skipping migrations. Run: php scripts/server.php start');
        }
    }

    /**
     * Returns true when the given Docker container is running.
     */
    private function isContainerRunning(string $containerName): bool
    {
        $out = [];
        exec(
            'docker inspect --format={{.State.Running}} ' . escapeshellarg($containerName) . ' 2>/dev/null',
            $out,
            $code,
        );

        return $code === 0 && trim($out[0] ?? '') === 'true';
    }
}
