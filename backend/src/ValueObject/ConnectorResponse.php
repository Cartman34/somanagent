<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\ValueObject;

/**
 * Immutable normalized connector response understood by all callers.
 */
class ConnectorResponse
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $content,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly float $durationMs = 0,
        public readonly ?string $sessionId = null,
        public readonly array $metadata = [],
        public readonly ?string $rawOutput = null,
    ) {}

    /**
     * @param array<string, mixed> $usage
     */
    public static function fromApi(string $content, array $usage = [], float $durationMs = 0, array $metadata = [], ?string $rawOutput = null): self
    {
        return new self(
            content: trim($content),
            inputTokens: (int) ($usage['input_tokens'] ?? 0),
            outputTokens: (int) ($usage['output_tokens'] ?? 0),
            durationMs: $durationMs,
            sessionId: is_string($metadata['session_id'] ?? null) ? $metadata['session_id'] : null,
            metadata: ['source' => 'api', ...$metadata],
            rawOutput: $rawOutput,
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function fromCli(string $output, float $durationMs = 0, array $metadata = [], ?string $rawOutput = null): self
    {
        return new self(
            content: trim($output),
            durationMs: $durationMs,
            sessionId: is_string($metadata['session_id'] ?? null) ? $metadata['session_id'] : null,
            metadata: ['source' => 'cli', ...$metadata],
            rawOutput: $rawOutput,
        );
    }

    /**
     * @param array<string, int> $usage
     * @param array<string, mixed> $metadata
     */
    public static function fromCliJson(string $content, float $durationMs = 0, array $usage = [], array $metadata = [], ?string $rawOutput = null): self
    {
        return new self(
            content: trim($content),
            inputTokens: (int) ($usage['input_tokens'] ?? 0),
            outputTokens: (int) ($usage['output_tokens'] ?? 0),
            durationMs: $durationMs,
            sessionId: is_string($metadata['session_id'] ?? null) ? $metadata['session_id'] : null,
            metadata: $metadata,
            rawOutput: $rawOutput,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'content' => $this->content,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'duration_ms' => $this->durationMs,
            'session_id' => $this->sessionId,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Returns whether the response carries no visible content.
     */
    public function isEmpty(): bool
    {
        return trim($this->content) === '';
    }
}
