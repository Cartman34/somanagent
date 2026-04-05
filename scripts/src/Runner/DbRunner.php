<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

/**
 * Database script runner.
 *
 * Runs database-related commands inside Docker containers (PostgreSQL + PHP).
 */
final class DbRunner extends AbstractScriptRunner
{
    protected function getDescription(): string
    {
        return 'Run database-related commands inside Docker containers (PostgreSQL + PHP)';
    }

    protected function getCommands(): array
    {
        return [
            ['name' => 'query', 'description' => 'Execute a SQL query'],
            ['name' => 'exec', 'description' => 'Run psql arguments interactively'],
            ['name' => 'shell', 'description' => 'Open a psql shell'],
            ['name' => 'reset', 'description' => 'Recreate the local database'],
        ];
    }

    protected function getOptions(): array
    {
        return [
            ['name' => '--fixtures', 'description' => 'Reload fixtures after reset'],
            ['name' => '--force', 'description' => 'Skip confirmation on reset'],
        ];
    }

    protected function getUsageExamples(): array
    {
        return [
            'php scripts/db.php query "SELECT 1"',
            'php scripts/db.php exec -c "\\dt"',
            'php scripts/db.php shell',
            'php scripts/db.php reset',
            'php scripts/db.php reset --fixtures',
            'php scripts/db.php reset --fixtures --force',
        ];
    }

    public function run(array $args): int
    {
        if ($args === []) {
            $this->console->line('Usage: php scripts/db.php query "SELECT 1"');
            $this->console->line('Usage: php scripts/db.php exec -c "\\dt"');
            $this->console->line('Usage: php scripts/db.php shell');
            $this->console->line('Usage: php scripts/db.php reset [--fixtures [--force]]');
            return 1;
        }

        try {
            $runner = new DoctrineRunner($this->app);
            return $runner->run($args);
        } catch (\InvalidArgumentException $e) {
            $this->console->fail($e->getMessage());
        }
    }
}
