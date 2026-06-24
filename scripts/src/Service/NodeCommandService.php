<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Service;

use Sowapps\SoManAgent\Script\Client\Docker\SoManAgentDockerCompose;
use Sowapps\Toolkit\Docker\Client\DockerComposeClientInterface;
use Sowapps\Toolkit\Docker\DockerCompose;

/**
 * Silent model resolving reusable developer commands for the Node container.
 *
 * Maps the supported Node sub-commands to the commands executed inside the node service of the
 * SoManAgent root stack, then runs them through the injected {@see DockerComposeClientInterface}.
 * The mapping preserves the legacy behaviour: type-check/build/lint run npm scripts, test runs
 * `npm test`, shell opens an interactive `sh` (TTY), run forwards to a named npm script, and exec
 * runs a raw command verbatim.
 *
 * This class is a silent thin model: it executes Docker operations or throws, but never formats
 * progress for the terminal. Orchestration and display live in
 * {@see \Sowapps\SoManAgent\Script\Runner\NodeRunner}.
 */
final class NodeCommandService
{
    private const CMD_TYPE_CHECK = 'type-check';

    private readonly SoManAgentDockerCompose $docker;

    public function __construct(
        private readonly DockerComposeClientInterface $client,
        DockerCompose $docker,
    ) {
        if (!$docker instanceof SoManAgentDockerCompose) {
            throw new \InvalidArgumentException(sprintf(
                'Expected %s, got %s.',
                SoManAgentDockerCompose::class,
                $docker::class,
            ));
        }

        $this->docker = $docker;
    }

    /**
     * Runs the requested Node-container command, throwing on failure.
     *
     * @param list<string> $args
     */
    public function run(array $args): void
    {
        if ($args === []) {
            throw new \InvalidArgumentException('Missing Node command.');
        }

        $node = $this->docker->main->node;

        match ($args[0]) {
            self::CMD_TYPE_CHECK => $this->client->run($node, ['npm', 'run', self::CMD_TYPE_CHECK]),
            'build' => $this->client->run($node, ['npm', 'run', 'build']),
            'lint' => $this->client->run($node, ['npm', 'run', 'lint']),
            'test' => $this->client->run($node, ['npm', 'test']),
            'shell' => $this->client->run($node, ['sh'], true),
            'run' => $this->runNamedScript(array_slice($args, 1)),
            'exec' => $this->runRawCommand(array_slice($args, 1)),
            default => throw new \InvalidArgumentException(sprintf('Unsupported Node command: %s', $args[0])),
        };
    }

    /**
     * @param list<string> $args
     */
    private function runNamedScript(array $args): void
    {
        if ($args === []) {
            throw new \InvalidArgumentException('Missing npm script name after "run".');
        }

        $this->client->run($this->docker->main->node, ['npm', 'run', ...$args]);
    }

    /**
     * @param list<string> $args
     */
    private function runRawCommand(array $args): void
    {
        if ($args === []) {
            throw new \InvalidArgumentException('Missing raw command after "exec".');
        }

        $this->client->run($this->docker->main->node, $args);
    }
}
