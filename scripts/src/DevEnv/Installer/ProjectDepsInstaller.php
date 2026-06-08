<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\DevEnv\Installer;

use Sowapps\Toolkit\ShellRunnerInterface;
use Sowapps\Toolkit\Console;
use Sowapps\SoManAgent\Script\DevEnv\CommandRunnerInterface;
use Sowapps\SoManAgent\Script\DevEnv\SystemCommandRunner;

/**
 * Runs project-level setup steps, mixing container-based and host-based execution.
 *
 * Not lockfile-driven — these steps are post-install project bootstrapping:
 *   1. composer install (in the php container — skipped with warning if not running)
 *   2. npm install (in the node container — skipped with warning if not running)
 *   3. Doctrine migrations via host PHP CLI (requires db container; fails if absent)
 *
 * Migrations use host PHP (`php backend/bin/console`) rather than `docker compose exec`
 * so that `setup.php install` works in minimal mode (db+redis only, no php container).
 * DATABASE_URL is normalized to `localhost:5432` regardless of the value in `.env`.
 */
final class ProjectDepsInstaller
{
    /**
     * @param ShellRunnerInterface $shell Runs shell commands (passthru-style)
     * @param Console $console Output helper
     * @param string                  $backendRoot  Absolute path to the backend/ directory
     * @param CommandRunnerInterface $dockerRunner Docker state queries (output-capture style)
     */
    public function __construct(
        private readonly ShellRunnerInterface $shell,
        private readonly Console $console,
        private readonly string $backendRoot,
        private readonly CommandRunnerInterface $dockerRunner = new SystemCommandRunner(),
    ) {
    }

    /**
     * Returns simulated commands for the project-level setup steps.
     *
     * Used during dry-run to display what would run without applying.
     *
     * @return list<string>
     */
    public function getSimulatedCommands(): array
    {
        try {
            $databaseUrl = $this->resolveDatabaseUrl();
        } catch (\RuntimeException) {
            $databaseUrl = '<DATABASE_URL>';
        }

        return [
            'docker compose exec -T php composer install',
            'docker compose exec -T node npm install',
            sprintf(
                'DATABASE_URL=%s php %s/bin/console doctrine:migrations:migrate --no-interaction',
                escapeshellarg($databaseUrl),
                $this->backendRoot,
            ),
        ];
    }

    /**
     * Runs project-level setup steps.
     *
     * composer and npm are skipped with a warning when their containers are absent.
     * Doctrine migrations are run via host PHP CLI; throws when the db container is not up.
     */
    public function runProjectSteps(): void
    {
        $this->console->step('Project setup steps');

        if ($this->isContainerRunning('somanagent_php')) {
            $this->console->info('Running composer install...');
            $code = $this->shell->runCommand('docker compose exec -T php composer install');
            if ($code !== 0) {
                throw new \RuntimeException(sprintf('composer install failed (exit %d)', $code));
            }
            $this->console->ok('composer install done.');
        } else {
            $this->console->warn('php container not running — skipping composer install. Run: php scripts/toolkit/server.php start');
        }

        if ($this->isContainerRunning('somanagent_node')) {
            $this->console->info('Running npm install...');
            $code = $this->shell->runCommand('docker compose exec -T node npm install');
            if ($code !== 0) {
                throw new \RuntimeException(sprintf('npm install failed (exit %d)', $code));
            }
            $this->console->ok('npm install done.');
        } else {
            $this->console->warn('node container not running — skipping npm install. Run: php scripts/toolkit/server.php start');
        }

        if (!$this->isContainerRunning('somanagent_db')) {
            throw new \RuntimeException(
                'db container is not running — start it with `php scripts/toolkit/server.php start minimal` or `php scripts/toolkit/server.php start`',
            );
        }

        $this->console->info('Running Doctrine migrations (host PHP CLI)...');
        $databaseUrl = $this->resolveDatabaseUrl();
        $cmd = sprintf(
            'DATABASE_URL=%s php %s/bin/console doctrine:migrations:migrate --no-interaction',
            escapeshellarg($databaseUrl),
            escapeshellarg($this->backendRoot),
        );
        $code = $this->shell->runCommand($cmd);
        if ($code !== 0) {
            throw new \RuntimeException(sprintf('Doctrine migrations failed (exit %d)', $code));
        }
        $this->console->ok('Migrations complete.');
    }

    /**
     * Returns true when the given Docker container is running.
     */
    private function isContainerRunning(string $containerName): bool
    {
        $cmd = 'docker inspect --format={{.State.Running}} ' . escapeshellarg($containerName) . ' 2>/dev/null';
        $output = $this->dockerRunner->output($cmd);

        return trim($output ?? '') === 'true';
    }

    /**
     * Reads DATABASE_URL from backend/.env (and .env.local override) and normalizes
     * the host to localhost so migrations run correctly from the host PHP CLI.
     *
     * The committed backend/.env uses `db:5432` (Docker service name). On the host,
     * the exposed port is `localhost:5432`. backend/.env.local may already override
     * this for agent worktrees; we normalize unconditionally for safety.
     */
    private function resolveDatabaseUrl(): string
    {
        $url = null;

        foreach ([$this->backendRoot . '/.env', $this->backendRoot . '/.env.local'] as $file) {
            if (!file_exists($file)) {
                continue;
            }
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                continue;
            }
            foreach ($lines as $line) {
                if (str_starts_with(ltrim($line), '#')) {
                    continue;
                }
                if (preg_match('/^DATABASE_URL=(.+)$/', $line, $m)) {
                    $url = trim($m[1], '"\'');
                }
            }
        }

        if ($url === null) {
            throw new \RuntimeException(
                'DATABASE_URL not found in backend/.env or backend/.env.local',
            );
        }

        // Replace Docker service hostname with localhost for host CLI execution.
        return (string) preg_replace('#@db:(\d+)/#', '@localhost:$1/', $url);
    }
}
