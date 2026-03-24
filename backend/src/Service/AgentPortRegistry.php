<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\ConnectorType;
use App\Port\AgentPort;

class AgentPortRegistry
{
    /** @var AgentPort[] */
    private array $adapters;

    /** @param iterable<AgentPort> $adapters */
    public function __construct(iterable $adapters)
    {
        $this->adapters = iterator_to_array($adapters);
    }

    public function getFor(ConnectorType $type): AgentPort
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->supportsConnector($type)) {
                return $adapter;
            }
        }

        throw new \RuntimeException(
            sprintf('Aucun adapter disponible pour le connecteur "%s"', $type->value)
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
