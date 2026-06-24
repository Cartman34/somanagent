<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Client\Docker;

use Sowapps\Toolkit\Application\ProjectRootProvider;
use Sowapps\Toolkit\Docker\DockerContainer;
use Sowapps\Toolkit\Docker\DockerStack;

/**
 * The SoManAgent root Docker Compose stack (docker-compose.yml at the project root).
 *
 * Derives the absolute compose-file path from the main project root and exposes each declared
 * service as a typed readonly {@see DockerContainer}. Container names are the exact Compose service
 * keys from docker-compose.yml (php, worker, nginx, node, mercure, redis, db).
 *
 * @api Concrete stack for the SoManAgent root compose file; inject the typed containers via {@see SoManAgentDockerCompose}.
 */
final class DockerMainStack extends DockerStack
{
    public readonly DockerContainer $php;
    public readonly DockerContainer $worker;
    public readonly DockerContainer $nginx;
    public readonly DockerContainer $node;
    public readonly DockerContainer $mercure;
    public readonly DockerContainer $redis;
    public readonly DockerContainer $db;

    public function __construct(ProjectRootProvider $rootProvider)
    {
        $projectRoot = $rootProvider->getMainProjectRoot();

        parent::__construct($projectRoot . '/docker-compose.yml', $projectRoot);

        $this->php     = new DockerContainer('php', $this);
        $this->worker  = new DockerContainer('worker', $this);
        $this->nginx   = new DockerContainer('nginx', $this);
        $this->node    = new DockerContainer('node', $this);
        $this->mercure = new DockerContainer('mercure', $this);
        $this->redis   = new DockerContainer('redis', $this);
        $this->db      = new DockerContainer('db', $this);
    }
}
