<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Service;

use App\Enum\ConnectorType;
use App\Port\ConnectorInterface;
use App\ValueObject\ConnectorDescriptor;
use App\ValueObject\ConnectorHealthChecks;
use App\ValueObject\ConnectorHealthReport;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Registry that maps connector types to their low-level connector implementations.
 */
class ConnectorRegistry
{
    private const PROMPT_TEST_CACHE_TTL = 300;

    /** @var ConnectorInterface[] */
    private array $connectors;

    /**
     * @param iterable<ConnectorInterface> $connectors
     */
    public function __construct(iterable $connectors, private readonly CacheItemPoolInterface $cache)
    {
        $this->connectors = iterator_to_array($connectors);
    }

    /**
     * Resolves the connector responsible for the given connector type.
     */
    public function getFor(ConnectorType $type): ConnectorInterface
    {
        foreach ($this->connectors as $connector) {
            if ($connector->supportsConnector($type)) {
                return $connector;
            }
        }

        throw new \RuntimeException(sprintf('No connector available for "%s".', $type->value));
    }

    /**
     * Returns descriptors for all registered connectors.
     *
     * When $deep is true, a real prompt is sent per connector and the result is stored in cache for 5 minutes.
     * When $deep is false (default), runtime and auth are checked live; the prompt_test result comes from cache
     * when available, or is marked as skipped when no cached result exists yet.
     *
     * @return ConnectorDescriptor[]
     */
    public function describeAll(bool $deep = false): array
    {
        $descriptors = [];

        foreach (ConnectorType::cases() as $type) {
            $connector = $this->getFor($type);
            $auth      = $connector->getAuthenticationStatus();
            $cacheKey  = 'connector_prompt_test_' . $type->value;
            $cacheItem = $this->cache->getItem($cacheKey);

            if ($deep) {
                $health = $connector->checkHealth(null, true);
                $cacheItem->set($health->checks->promptTest)->expiresAfter(self::PROMPT_TEST_CACHE_TTL);
                $this->cache->save($cacheItem);
            } else {
                $health = $connector->checkHealth(null, false);

                if ($cacheItem->isHit()) {
                    $health = new ConnectorHealthReport(
                        connector: $type,
                        checks: new ConnectorHealthChecks(
                            runtime:    $health->checks->runtime,
                            auth:       $health->checks->auth,
                            promptTest: $cacheItem->get(),
                            models:     $health->checks->models,
                        ),
                    );
                }
            }

            $descriptors[] = new ConnectorDescriptor(
                connector: $type,
                connectorClass: $connector::class,
                health: $health,
                authentication: $auth,
                supportsModelDiscovery: $connector->supportsModelDiscovery(),
            );
        }

        return $descriptors;
    }

    /**
     * @return array<string, bool>
     */
    public function healthCheckAll(): array
    {
        $results = [];

        foreach ($this->describeAll() as $descriptor) {
            $results[$descriptor->connector->value] = $descriptor->isOverallHealthy();
        }

        return $results;
    }
}
