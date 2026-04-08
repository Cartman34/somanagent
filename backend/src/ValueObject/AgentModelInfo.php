<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\ValueObject;

/**
 * Normalized model descriptor returned by any agent connector.
 */
final readonly class AgentModelInfo
{
    /**
     * Builds the normalized model descriptor shared by API, CLI, and cache layers.
     *
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $label,
        public ?string $provider = null,
        public ?string $family = null,
        public ?string $description = null,
        public ?int $contextWindow = null,
        public ?int $maxOutputTokens = null,
        public ?string $status = null,
        public ?string $releaseDate = null,
        public ?AgentModelPricing $pricing = null,
        public ?AgentModelCapabilities $capabilities = null,
        public array $metadata = [],
    ) {}

    /**
     * Returns the model descriptor serialized for API responses and cache storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'provider' => $this->provider,
            'family' => $this->family,
            'description' => $this->description,
            'contextWindow' => $this->contextWindow,
            'maxOutputTokens' => $this->maxOutputTokens,
            'status' => $this->status,
            'releaseDate' => $this->releaseDate,
            'pricing' => $this->pricing?->toArray(),
            'capabilities' => $this->capabilities?->toArray(),
            'metadata' => $this->metadata,
        ];
    }
}
