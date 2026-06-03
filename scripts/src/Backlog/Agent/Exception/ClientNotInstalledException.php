<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Agent\Exception;

use Sowapps\SoManAgent\Script\Backlog\Agent\Enum\AgentClient;

/**
 * Thrown when the binary for a requested AI client is not available in PATH,
 * or when no launcher has been registered for that client.
 */
final class ClientNotInstalledException extends \RuntimeException
{
    /**
     * @param AgentClient $client The client that has no registered launcher
     */
    public function __construct(AgentClient $client)
    {
        parent::__construct(sprintf(
            "Client '%s' is not available.\n  Install it: php scripts/setup.php install",
            $client->value,
        ));
    }
}
