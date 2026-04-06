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

    /**
     * Runs Doctrine migrations through the shared Doctrine runner.
     *
     * @param list<string> $args
     */
    public function run(array $args): int
    {
        try {
            return (new DoctrineRunner($this->app))->run(['migrate', ...$args]);
        } catch (\RuntimeException $e) {
            $this->console->fail($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            $this->console->fail($e->getMessage());
        }
    }
}
