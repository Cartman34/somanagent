<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Client\Docker;

use Sowapps\Toolkit\Docker\DockerCompose;

/**
 * SoManAgent project-level Docker Compose context.
 *
 * Assembles the project stacks as typed readonly properties. Registered in DI and aliased to
 * {@see DockerCompose} so consumers inject the base type and reach containers via, e.g.,
 * {@see $main}->node.
 *
 * @api Concrete project compose context; consumers inject {@see DockerCompose} (the aliased base type).
 */
final class SoManAgentDockerCompose extends DockerCompose
{
    public readonly DockerMainStack $main;

    public function __construct(DockerMainStack $main)
    {
        $this->main = $main;
    }
}
