<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

use SoManAgent\Script\Application;
use SoManAgent\Script\Console;

/**
 * Runs Doctrine commands (migrations, fixtures) and psql commands.
 */
final class DoctrineRunner
{
    private const DEFAULT_PSQL_ARGS = ['psql', '-U', 'somanagent', '-d', 'somanagent'];

    private DockerComposeServiceRunner $phpRunner;
    private DockerComposeServiceRunner $dbRunner;
    private readonly Console $console;

    public function __construct(
        private readonly Application $app,
    ) {
        $this->phpRunner = new DockerComposeServiceRunner($app, 'php');
        $this->dbRunner = new DockerComposeServiceRunner($app, 'db');
        $this->console = $app->console;
    }

    /**
     * @param list<string> $args
     */
    public function run(array $args): int
    {
        if ($args === []) {
            throw new \InvalidArgumentException('Missing database command.');
        }

        return match ($args[0]) {
            'shell' => $this->dbRunner->run(self::DEFAULT_PSQL_ARGS, true),
            'query' => $this->runQuery(array_slice($args, 1)),
            'exec' => $this->runExec(array_slice($args, 1)),
            'reset' => $this->runReset(array_slice($args, 1)),
            default => throw new \InvalidArgumentException(sprintf('Unsupported database command: %s', $args[0])),
        };
    }

    /**
     * @param list<string> $args
     */
    private function runQuery(array $args): int
    {
        if ($args === []) {
            throw new \InvalidArgumentException('Missing SQL query after "query".');
        }

        return $this->dbRunner->run([...self::DEFAULT_PSQL_ARGS, '-c', $args[0]]);
    }

    /**
     * @param list<string> $args
     */
    private function runExec(array $args): int
    {
        if ($args === []) {
            throw new \InvalidArgumentException('Missing psql arguments after "exec".');
        }

        return $this->dbRunner->run([...self::DEFAULT_PSQL_ARGS, ...$args]);
    }

    /**
     * @param list<string> $args
     */
    private function runReset(array $args): int
    {
        $c = $this->console;
        $withFixtures = in_array('--fixtures', $args, true);
        $force = in_array('--force', $args, true);

        if (!$force) {
            $target = $withFixtures
                ? 'This will recreate the local database and reload fixtures.'
                : 'This will recreate the local database.';

            $c->warn($target);
            $c->warn('All current local data will be lost.');
            $c->line('  Type "yes" to continue:');

            $confirmation = trim((string) fgets(STDIN));
            if ($confirmation !== 'yes') {
                $c->fail('Aborted.', 0);
            }
        }

        try {
            $c->step('Starting required containers');
            $code = $this->app->runCommand('docker compose up -d db php');
            if ($code !== 0) {
                throw new \RuntimeException("docker compose up failed (exit $code).");
            }

            $c->step('Dropping local database');
            $code = $this->phpRunner->run(['php', 'bin/console', 'doctrine:database:drop', '--if-exists', '--force']);
            if ($code !== 0) {
                throw new \RuntimeException("Database drop failed (exit $code).");
            }

            $c->step('Creating local database');
            $code = $this->phpRunner->run(['php', 'bin/console', 'doctrine:database:create']);
            if ($code !== 0) {
                throw new \RuntimeException("Database creation failed (exit $code).");
            }

            $c->step('Running migrations');
            $code = $this->phpRunner->run(['php', 'bin/console', 'doctrine:migrations:migrate', '--no-interaction']);
            if ($code !== 0) {
                throw new \RuntimeException("Migration command failed (exit $code).");
            }

            if ($withFixtures) {
                $c->step('Reloading fixtures');
                $code = $this->phpRunner->run(['php', 'bin/console', 'doctrine:fixtures:load', '--no-interaction']);
                if ($code !== 0) {
                    throw new \RuntimeException("Fixtures load failed (exit $code).");
                }
            }
        } catch (\RuntimeException $e) {
            $c->fail($e->getMessage());
        }

        $c->ok($withFixtures ? 'Database recreated and fixtures reloaded.' : 'Database recreated.');

        return 0;
    }
}
