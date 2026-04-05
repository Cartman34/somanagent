<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

/**
 * Migrate script runner.
 *
 * Runs Doctrine migrations inside the PHP container.
 */
final class MigrateRunner extends AbstractScriptRunner
{
    protected function getDescription(): string
    {
        return 'Run Doctrine migrations inside the PHP container';
    }

    protected function getOptions(): array
    {
        return [
            ['name' => '--dry-run', 'description' => 'Show SQL queries without executing'],
        ];
    }

    protected function getUsageExamples(): array
    {
        return [
            'php scripts/migrate.php',
            'php scripts/migrate.php --dry-run',
        ];
    }

    public function run(array $args): int
    {
        $dryRun = in_array('--dry-run', $args, true);

        $this->console->step('Doctrine migrations' . ($dryRun ? ' (dry-run)' : ''));

        try {
            $args = ['doctrine:migrations:migrate', '--no-interaction'];
            if ($dryRun) {
                $args[] = '--dry-run';
            }

            $escapedArgs = implode(' ', array_map('escapeshellarg', $args));
            $code        = $this->app->runCommand("docker compose exec -T php php bin/console $escapedArgs");

            if ($code !== 0) {
                throw new \RuntimeException("Migration command failed (exit $code).");
            }

            $this->console->ok('Migrations complete.');
        } catch (\RuntimeException $e) {
            $this->console->fail($e->getMessage());
        }

        return 0;
    }
}
