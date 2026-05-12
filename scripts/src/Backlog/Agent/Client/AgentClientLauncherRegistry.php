<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Client;

use SoManAgent\Script\Backlog\Agent\Enum\AgentClient;
use SoManAgent\Script\Backlog\Agent\Exception\ClientNotInstalledException;

/**
 * Maps AgentClient enum values to their concrete AgentClientLauncher.
 *
 * A client absent from the registry is treated as not installed: any lookup
 * throws ClientNotInstalledException with a remediation hint pointing to
 * php scripts/install-clients.php.
 */
final class AgentClientLauncherRegistry
{
    /** @var array<string, AgentClientLauncher> */
    private array $launchers = [];

    /**
     * Registers a concrete launcher for its declared client.
     */
    public function register(AgentClientLauncher $launcher): void
    {
        $this->launchers[$launcher->client()->value] = $launcher;
    }

    /**
     * Returns the launcher for the given client.
     *
     * @throws ClientNotInstalledException when no launcher is registered for the client
     */
    public function get(AgentClient $client): AgentClientLauncher
    {
        $launcher = $this->launchers[$client->value] ?? null;
        if ($launcher === null) {
            throw new ClientNotInstalledException($client);
        }

        return $launcher;
    }

    /**
     * Returns true when a launcher is registered for the given client.
     */
    public function has(AgentClient $client): bool
    {
        return isset($this->launchers[$client->value]);
    }

    /**
     * @return list<AgentClientLauncher>
     */
    public function all(): array
    {
        return array_values($this->launchers);
    }
}
