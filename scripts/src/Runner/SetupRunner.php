<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

use SoManAgent\Script\Environment;

/**
 * Full setup script runner.
 *
 * Handles first-time setup of SoManAgent including Docker, dependencies, and migrations.
 */
final class SetupRunner extends AbstractScriptRunner
{
    protected function getDescription(): string
    {
        return 'Full setup of SoManAgent (first run)';
    }

    protected function getOptions(): array
    {
        return [
            ['name' => '--skip-frontend', 'description' => 'Skip frontend dependency installation'],
        ];
    }

    protected function getUsageExamples(): array
    {
        return [
            'php scripts/setup.php',
            'php scripts/setup.php --skip-frontend',
        ];
    }

    /**
     * Runs the end-to-end local setup flow, with an option to skip frontend dependencies.
     */
    public function run(array $args): int
    {
        $skipFrontend = in_array('--skip-frontend', $args, true);

        $run = function (string $cmd): void {
            $code = $this->app->runCommand($cmd);
            if ($code !== 0) {
                throw new \RuntimeException("Command failed (exit $code): $cmd");
            }
        };

        $this->console->hr();
        $this->console->line('     SoManAgent — Initial setup');
        $this->console->hr();

        if (Environment::isOnWindowsFilesystem()) {
            $this->handleWindowsFilesystemWarning();
        }

        try {
            $this->console->step('Checking PHP version');
            $run('bash scripts/check-php.sh');

            $this->console->step('Checking .env file');
            if (!file_exists("{$this->projectRoot}/.env")) {
                copy("{$this->projectRoot}/.env.dist", "{$this->projectRoot}/.env");
                $this->console->ok('.env created from .env.dist');
                $this->console->warn('Fill in the values in .env then re-run this script.');
                return 0;
            }
            $this->console->ok('.env present');

            $this->console->step('Preparing Docker shared auth directories');
            $this->ensureDockerAuthDirectories();
            $this->console->ok('Shared auth directories ready');

            $this->console->step('Starting Docker containers');
            $run('docker compose up -d --build');
            $this->console->ok('Containers started');

            $this->console->step('Installing PHP dependencies (composer)');
            $run('docker compose exec -T php composer install');
            $this->console->ok('Composer dependencies installed');

            $this->console->step('Waiting for PostgreSQL');
            $this->waitForPostgreSQL();
            $this->console->ok('PostgreSQL ready');

            $this->console->step('Running Doctrine migrations');
            $run('docker compose exec -T php php bin/console doctrine:migrations:migrate --no-interaction');
            $this->console->ok('Migrations complete');

            if (!$skipFrontend) {
                $this->console->step('Installing frontend dependencies (npm)');
                $run('docker compose exec -T node npm install');
                $this->console->ok('npm install done');
            } else {
                $this->console->info('Frontend skipped (--skip-frontend)');
            }
        } catch (\RuntimeException $e) {
            $this->console->fail($e->getMessage());
        }

        $this->console->line();
        $this->console->hr();
        $this->console->line('  ✓ SoManAgent is ready!');
        $this->console->line();
        $this->console->line('  API  →  http://localhost:8080/api/health');
        $this->console->line('  UI   →  http://localhost:5173');
        $this->console->hr();
        $this->console->line();

        return 0;
    }

    private function handleWindowsFilesystemWarning(): void
    {
        $this->console->line();
        $this->console->warn('Performance warning: project is on the Windows filesystem.');
        $this->console->warn('  Path : ' . getcwd());
        $this->console->warn('  Docker bind mounts from /mnt/* use the 9P protocol over Hyper-V.');
        $this->console->warn('  This causes 5-20x slower PHP/DB I/O (e.g. 9s for a simple query).');
        $this->console->line();
        $this->console->info('Fix → migrate the project to the WSL native ext4 filesystem:');
        $this->console->info('  bash scripts/wsl-migrate.sh');
        $this->console->line();
        $this->console->info('After migration, run this script again from the new location.');
        $this->console->info('Your IDE can access WSL files via:');
        $this->console->info('  \\\\wsl.localhost\\' . (getenv('WSL_DISTRO_NAME') ?: 'Ubuntu') . '\home\<user>\somanagent');
        $this->console->line();

        if (!posix_isatty(STDIN)) {
            $this->console->fail('Aborting: migrate to WSL filesystem first for acceptable performance.');
        }

        echo '  Continue anyway? This will be slow. [y/N] ';
        $answer = trim(fgets(STDIN) ?: '');
        if (!preg_match('/^[yY]/', $answer)) {
            $this->console->fail('Aborted. Run: bash scripts/wsl-migrate.sh');
        }
        $this->console->line();
    }

    /**
     * Creates the Docker shared auth directories before containers start.
     *
     * These directories are bind-mounted in docker-compose.yml but are excluded from git (.docker/ is
     * gitignored). Without pre-creation, Docker creates them as root on first run, which causes
     * permission errors in the auth sync scripts running as the current user.
     */
    private function ensureDockerAuthDirectories(): void
    {
        $dirs = [
            '.docker/claude/shared/.claude',
            '.docker/codex/shared/.codex',
            '.docker/opencode/shared/.local',
        ];

        foreach ($dirs as $dir) {
            $path = $this->projectRoot . '/' . $dir;
            if (!is_dir($path)) {
                if (!mkdir($path, 0o755, recursive: true)) {
                    throw new \RuntimeException(sprintf('Failed to create directory: %s', $path));
                }
                $this->console->info(sprintf('Created %s', $dir));
            }
        }
    }

    private function waitForPostgreSQL(): void
    {
        $tries  = 0;
        $status = '';
        do {
            sleep(1);
            $tries++;
            exec('docker inspect --format={{.State.Health.Status}} somanagent_db 2>&1', $out, $code);
            $status = trim($out[0] ?? '');
            $out    = [];
        } while ($status !== 'healthy' && $tries < 30);

        if ($status !== 'healthy') {
            throw new \RuntimeException(
                "PostgreSQL did not become healthy after {$tries}s.\n" .
                "  Run: docker compose logs db"
            );
        }
    }
}
