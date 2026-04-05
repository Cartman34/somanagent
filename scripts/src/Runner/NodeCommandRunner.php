<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

use SoManAgent\Script\Application;

/**
 * Resolves reusable developer commands for the Node container.
 */
final class NodeCommandRunner
{
    private DockerComposeServiceRunner $runner;

    public function __construct(Application $app)
    {
        $this->runner = new DockerComposeServiceRunner($app, 'node');
    }

    /**
     * Runs the requested Node-container command.
     *
     * @param list<string> $args
     */
    public function run(array $args): int
    {
        if ($args === []) {
            throw new \InvalidArgumentException('Missing Node command.');
        }

        return match ($args[0]) {
            'type-check' => $this->runner->run(['npm', 'run', 'type-check']),
            'build' => $this->runner->run(['npm', 'run', 'build']),
            'lint' => $this->runner->run(['npm', 'run', 'lint']),
            'test' => $this->runner->run(['npm', 'test']),
            'shell' => $this->runner->run(['sh'], true),
            'run' => $this->runNamedScript(array_slice($args, 1)),
            'exec' => $this->runRawCommand(array_slice($args, 1)),
            default => throw new \InvalidArgumentException(sprintf('Unsupported Node command: %s', $args[0])),
        };
    }

    /**
     * @param list<string> $args
     */
    private function runNamedScript(array $args): int
    {
        if ($args === []) {
            throw new \InvalidArgumentException('Missing npm script name after "run".');
        }

        return $this->runner->run(['npm', 'run', ...$args]);
    }

    /**
     * @param list<string> $args
     */
    private function runRawCommand(array $args): int
    {
        if ($args === []) {
            throw new \InvalidArgumentException('Missing raw command after "exec".');
        }

        return $this->runner->run($args);
    }
}
