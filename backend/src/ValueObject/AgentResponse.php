<?php

declare(strict_types=1);

namespace App\ValueObject;

/**
 * Réponse immuable reçue d'un agent IA.
 */
final class AgentResponse
{
    private function __construct(
        public readonly string $content,
        public readonly int    $inputTokens  = 0,
        public readonly int    $outputTokens = 0,
        public readonly float  $durationMs   = 0,
        public readonly array  $metadata     = [],
    ) {}

    public static function fromApi(string $content, array $usage = [], float $durationMs = 0): self
    {
        return new self(
            content:      $content,
            inputTokens:  $usage['input_tokens']  ?? 0,
            outputTokens: $usage['output_tokens'] ?? 0,
            durationMs:   $durationMs,
            metadata:     ['source' => 'api'],
        );
    }

    public static function fromCli(string $output, float $durationMs = 0): self
    {
        return new self(
            content:    trim($output),
            durationMs: $durationMs,
            metadata:   ['source' => 'cli'],
        );
    }

    public function toArray(): array
    {
        return [
            'content'       => $this->content,
            'input_tokens'  => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'duration_ms'   => $this->durationMs,
            'metadata'      => $this->metadata,
        ];
    }

    public function isEmpty(): bool
    {
        return trim($this->content) === '';
    }
}
