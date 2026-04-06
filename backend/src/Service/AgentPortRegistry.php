<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Service;

use App\Enum\ConnectorType;
use App\Port\AgentPort;

/**
 * Registry that maps connector types to their corresponding AgentPort implementations.
 */
class AgentPortRegistry
{
    /** @var AgentPort[] */
    private array $adapters;

    /** @param iterable<AgentPort> $adapters */
    public function __construct(iterable $adapters)
    {
        $this->adapters = iterator_to_array($adapters);
    }

    /**
     * Returns the adapter supporting the requested connector type.
     */
    public function getFor(ConnectorType $type): AgentPort
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->supportsConnector($type)) {
                return $adapter;
            }
        }

        throw new \RuntimeException(
            sprintf('No adapter available for connector "%s".', $type->value)
        );
    }

    /** @return array<string, bool> */
    public function healthCheckAll(): array
    {
        $results = [];
        foreach ($this->adapters as $adapter) {
            foreach (ConnectorType::cases() as $type) {
                if ($adapter->supportsConnector($type)) {
                    $results[$type->value] = $adapter->healthCheck();
                }
            }
        }
        return $results;
    }
}
