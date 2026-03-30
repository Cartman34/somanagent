<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

require_once __DIR__ . '/DockerComposeServiceRunner.php';

/**
 * Resolves reusable psql commands for the PostgreSQL container.
 */
final class PostgresCommandRunner
{
    private const DEFAULT_PSQL_ARGS = ['psql', '-U', 'somanagent', '-d', 'somanagent'];

    private DockerComposeServiceRunner $runner;

    public function __construct(Application $app)
    {
        $this->runner = new DockerComposeServiceRunner($app, 'db');
    }

    /**
     * Runs the requested PostgreSQL-container command.
     *
     * @param list<string> $args
     */
    public function run(array $args): int
    {
        if ($args === []) {
            throw new \InvalidArgumentException('Missing database command.');
        }

        return match ($args[0]) {
            'shell' => $this->runner->run(self::DEFAULT_PSQL_ARGS, true),
            'query' => $this->runQuery(array_slice($args, 1)),
            'exec' => $this->runExec(array_slice($args, 1)),
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

        return $this->runner->run([...self::DEFAULT_PSQL_ARGS, '-c', $args[0]]);
    }

    /**
     * @param list<string> $args
     */
    private function runExec(array $args): int
    {
        if ($args === []) {
            throw new \InvalidArgumentException('Missing psql arguments after "exec".');
        }

        return $this->runner->run([...self::DEFAULT_PSQL_ARGS, ...$args]);
    }
}
