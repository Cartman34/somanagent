<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\ValueObject;

use App\Enum\ConnectorType;

/**
 * Immutable connector catalog view exposed to the API and CLI.
 */
final readonly class AgentConnectorCatalog
{
    /**
     * Builds the immutable connector catalog view exposed by the API and CLI layers.
     *
     * @param AgentModelInfo[]     $models
     * @param AgentModelAdvisory[] $advisories
     */
    public function __construct(
        public ConnectorType $connector,
        public bool $supportsPromptExecution,
        public bool $supportsModelDiscovery,
        public string $selectionStrategy,
        public ?string $recommendedModel,
        public array $models,
        public array $advisories,
        public bool $cached,
        public int $cacheTtlSeconds,
    ) {}

    /**
     * Returns the connector catalog serialized for API responses.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'connector' => $this->connector->value,
            'label' => $this->connector->label(),
            'supportsPromptExecution' => $this->supportsPromptExecution,
            'supportsModelDiscovery' => $this->supportsModelDiscovery,
            'selectionStrategy' => $this->selectionStrategy,
            'recommendedModel' => $this->recommendedModel,
            'models' => array_map(static fn (AgentModelInfo $model): array => $model->toArray(), $this->models),
            'advisories' => array_map(static fn (AgentModelAdvisory $advisory): array => $advisory->toArray(), $this->advisories),
            'cached' => $this->cached,
            'cacheTtlSeconds' => $this->cacheTtlSeconds,
        ];
    }
}
