<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Service;

use App\Enum\ConnectorType;
use App\Port\ConnectorInterface;
use App\ValueObject\AgentConnectorCatalog;
use App\ValueObject\AgentModelInfo;
use App\ValueObject\AgentModelAdvisory;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Exposes normalized connector model catalogs with short-lived caching and recommendation metadata.
 */
class AgentModelCatalogService
{
    private const CACHE_TTL_SECONDS = 600;

    /**
     * Wires the registry, recommendation policy, and cache used to build connector model catalogs.
     */
    public function __construct(
        private readonly ConnectorRegistry $agentPortRegistry,
        private readonly AgentModelRecommendationPolicyResolver $agentModelRecommendationPolicyResolver,
        private readonly CacheItemPoolInterface $cache,
    ) {}

    /**
     * Builds lightweight connector catalogs without triggering model discovery for every connector.
     *
     * @return AgentConnectorCatalog[]
     */
    public function listConnectors(): array
    {
        return array_map(
            function (ConnectorType $connector): AgentConnectorCatalog {
                $adapter = $this->agentPortRegistry->getFor($connector);

                return new AgentConnectorCatalog(
                    connector: $connector,
                    supportsPromptExecution: true,
                    supportsModelDiscovery: $adapter->supportsModelDiscovery(),
                    selectionStrategy: 'balanced_coding',
                    recommendedModel: null,
                    models: [],
                    advisories: [],
                    cached: false,
                    cacheTtlSeconds: self::CACHE_TTL_SECONDS,
                );
            },
            ConnectorType::cases(),
        );
    }

    /**
     * Builds the full normalized catalog for one connector, including discovery, recommendation, and advisories.
     */
    public function describeConnector(
        ConnectorType $connector,
        ?string $selectedModel = null,
        bool $refresh = false,
    ): AgentConnectorCatalog {
        $adapter = $this->agentPortRegistry->getFor($connector);
        $supportsModelDiscovery = $adapter->supportsModelDiscovery();
        $cached = false;
        $error = null;
        $models = $this->loadModels($adapter, $connector, $refresh, $cached, $error);
        $recommendedModel = $this->agentModelRecommendationPolicyResolver->recommend($connector, $models);

        return new AgentConnectorCatalog(
            connector: $connector,
            supportsPromptExecution: true,
            supportsModelDiscovery: $supportsModelDiscovery,
            selectionStrategy: 'balanced_coding',
            recommendedModel: $recommendedModel,
            models: $models,
            advisories: $this->buildAdvisories($models, $supportsModelDiscovery, $selectedModel, $recommendedModel, $error),
            cached: $cached,
            cacheTtlSeconds: self::CACHE_TTL_SECONDS,
        );
    }

    /**
     * Builds the advisory list that explains discovery failures or non-recommended explicit model choices.
     *
     * @param AgentModelInfo[] $models
     * @return AgentModelAdvisory[]
     */
    private function buildAdvisories(
        array $models,
        bool $supportsModelDiscovery,
        ?string $selectedModel,
        ?string $recommendedModel,
        ?string $discoveryError,
    ): array {
        $advisories = [];

        if ($discoveryError !== null) {
            $advisories[] = new AgentModelAdvisory(
                level: 'warning',
                code: 'model_discovery_unavailable',
                message: $discoveryError,
            );
        }

        if ($selectedModel === null || $selectedModel === '' || !$supportsModelDiscovery || $discoveryError !== null) {
            if ($recommendedModel !== null) {
                $advisories[] = new AgentModelAdvisory(
                    level: 'info',
                    code: 'recommended_model_available',
                    message: sprintf(
                        'Use %s unless you have a specific latency or cost constraint.',
                        $recommendedModel,
                    ),
                );
            }

            return $advisories;
        }

        $selected = $this->findModel($models, $selectedModel);

        if ($selected === null) {
            $advisories[] = new AgentModelAdvisory(
                level: 'warning',
                code: 'selected_model_not_discovered',
                message: sprintf(
                    'The selected model "%s" is not present in the discovered catalog for this connector.',
                    $selectedModel,
                ),
            );

            return $advisories;
        }

        if ($recommendedModel !== null && $selectedModel !== $recommendedModel) {
            $advisories[] = new AgentModelAdvisory(
                level: 'info',
                code: 'selected_model_differs_from_recommendation',
                message: sprintf(
                    'The selected model "%s" differs from the recommended balanced coding model "%s".',
                    $selectedModel,
                    $recommendedModel,
                ),
            );
        }

        return $advisories;
    }

    /**
     * Finds the exact discovered model matching the selected model id.
     *
     * @param AgentModelInfo[] $models
     */
    private function findModel(array $models, string $modelId): ?AgentModelInfo
    {
        foreach ($models as $model) {
            if ($model->id === $modelId) {
                return $model;
            }
        }

        return null;
    }

    /**
     * Loads the connector model catalog from cache when possible, otherwise triggers live discovery.
     *
     * @param-out bool $cached
     * @param-out string|null $error
     * @return AgentModelInfo[]
     */
    private function loadModels(
        ConnectorInterface $adapter,
        ConnectorType $connector,
        bool $refresh,
        bool &$cached,
        ?string &$error,
    ): array {
        $cached = false;
        $error = null;

        if (!$adapter->supportsModelDiscovery()) {
            return [];
        }

        $cacheKey = sprintf('agent_model_catalog.%s', $connector->value);

        if ($refresh) {
            $this->cache->deleteItem($cacheKey);
        }

        $item = $this->cache->getItem($cacheKey);

        if ($item->isHit()) {
            $cached = true;
            $rawModels = $item->get();
            return $this->hydrateModels(is_array($rawModels) ? $rawModels : []);
        }

        try {
            $models = $adapter->discoverModels();
            usort($models, fn (AgentModelInfo $left, AgentModelInfo $right): int => strcmp($left->id, $right->id));
            $item->set(array_map(fn (AgentModelInfo $model): array => $model->toArray(), $models));
            $item->expiresAfter(self::CACHE_TTL_SECONDS);
            $this->cache->save($item);

            return $models;
        } catch (\Throwable $throwable) {
            $error = $throwable->getMessage();
            return [];
        }
    }

    /**
     * Rebuilds typed model descriptors from the normalized cache payload stored for one connector.
     *
     * @param array<int, mixed> $rawModels
     * @return AgentModelInfo[]
     */
    private function hydrateModels(array $rawModels): array
    {
        $models = [];

        foreach ($rawModels as $rawModel) {
            if (!is_array($rawModel) || !is_string($rawModel['id'] ?? null) || !is_string($rawModel['label'] ?? null)) {
                continue;
            }

            $models[] = new AgentModelInfo(
                id: $rawModel['id'],
                label: $rawModel['label'],
                provider: is_string($rawModel['provider'] ?? null) ? $rawModel['provider'] : null,
                family: is_string($rawModel['family'] ?? null) ? $rawModel['family'] : null,
                description: is_string($rawModel['description'] ?? null) ? $rawModel['description'] : null,
                contextWindow: is_int($rawModel['contextWindow'] ?? null) ? $rawModel['contextWindow'] : null,
                maxOutputTokens: is_int($rawModel['maxOutputTokens'] ?? null) ? $rawModel['maxOutputTokens'] : null,
                status: is_string($rawModel['status'] ?? null) ? $rawModel['status'] : null,
                releaseDate: is_string($rawModel['releaseDate'] ?? null) ? $rawModel['releaseDate'] : null,
                pricing: is_array($rawModel['pricing'] ?? null) ? \App\ValueObject\AgentModelPricing::fromArray($rawModel['pricing']) : null,
                capabilities: is_array($rawModel['capabilities'] ?? null) ? \App\ValueObject\AgentModelCapabilities::fromArray($rawModel['capabilities']) : null,
                metadata: is_array($rawModel['metadata'] ?? null) ? $rawModel['metadata'] : [],
            );
        }

        return $models;
    }
}
