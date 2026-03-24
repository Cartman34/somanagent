<?php

declare(strict_types=1);

namespace App\Domain\Agent;

/**
 * Configuration d'un agent : modèle, paramètres, options du connecteur.
 * Stocké en JSONB dans PostgreSQL.
 */
final class AgentConfig
{
    public function __construct(
        public readonly string $model,             // Ex: "claude-opus-4-5", "claude-sonnet-4-5"
        public readonly int $maxTokens = 8192,
        public readonly float $temperature = 0.7,
        public readonly array $extraParams = [],   // Paramètres spécifiques au connecteur
    ) {}

    public function toArray(): array
    {
        return [
            'model'       => $this->model,
            'max_tokens'  => $this->maxTokens,
            'temperature' => $this->temperature,
            'extra'       => $this->extraParams,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            model:       $data['model'],
            maxTokens:   $data['max_tokens'] ?? 8192,
            temperature: $data['temperature'] ?? 0.7,
            extraParams: $data['extra'] ?? [],
        );
    }
}
