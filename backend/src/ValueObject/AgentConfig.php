<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\ValueObject;

/**
 * Configuration immuable d'un agent IA.
 * Stockée en JSON dans la colonne config de l'entité Agent.
 */
final class AgentConfig
{
    public function __construct(
        public readonly string $model,
        public readonly int    $maxTokens   = 8192,
        public readonly float  $temperature = 0.7,
        public readonly int    $timeout     = 120,
        public readonly array  $extraParams = [],
    ) {}

    public function toArray(): array
    {
        return [
            'model'       => $this->model,
            'max_tokens'  => $this->maxTokens,
            'temperature' => $this->temperature,
            'timeout'     => $this->timeout,
            'extra'       => $this->extraParams,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            model:       $data['model'],
            maxTokens:   $data['max_tokens']  ?? 8192,
            temperature: $data['temperature'] ?? 0.7,
            timeout:     $data['timeout']     ?? 120,
            extraParams: $data['extra']       ?? [],
        );
    }

    public static function default(): self
    {
        return new self(model: 'claude-sonnet-4-5');
    }
}
