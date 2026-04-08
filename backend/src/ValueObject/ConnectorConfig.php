<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\ValueObject;

/**
 * Immutable connector execution settings independent from any agent entity.
 */
class ConnectorConfig
{
    /**
     * @param array<string, mixed> $extraParams
     */
    public function __construct(
        public readonly ?string $model = null,
        public readonly int $maxTokens = 8192,
        public readonly float $temperature = 0.7,
        public readonly int $timeout = 120,
        public readonly array $extraParams = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'model' => $this->model ?? '',
            'max_tokens' => $this->maxTokens,
            'temperature' => $this->temperature,
            'timeout' => $this->timeout,
            'extra' => $this->extraParams,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            model: ($data['model'] ?? null) !== null && $data['model'] !== '' ? (string) $data['model'] : null,
            maxTokens: (int) ($data['max_tokens'] ?? 8192),
            temperature: (float) ($data['temperature'] ?? 0.7),
            timeout: (int) ($data['timeout'] ?? 120),
            extraParams: is_array($data['extra'] ?? null) ? $data['extra'] : [],
        );
    }

    /**
     * Returns the generic default connector execution settings.
     */
    public static function default(): self
    {
        return new self();
    }
}
