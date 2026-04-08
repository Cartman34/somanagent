<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\ValueObject;

/**
 * Immutable capability snapshot for one model.
 */
final readonly class AgentModelCapabilities
{
    /**
     * Builds the immutable capability snapshot used by connector model catalogs.
     *
     * @param array<string, bool> $input
     * @param array<string, bool> $output
     * @param array<string, mixed> $additional
     */
    public function __construct(
        public ?bool $reasoning = null,
        public ?bool $toolCall = null,
        public ?bool $attachment = null,
        public ?bool $temperature = null,
        public array $input = [],
        public array $output = [],
        public array $additional = [],
    ) {}

    /**
     * Returns the normalized capability payload exposed by the API and CLI layers.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'reasoning' => $this->reasoning,
            'toolCall' => $this->toolCall,
            'attachment' => $this->attachment,
            'temperature' => $this->temperature,
            'input' => $this->input,
            'output' => $this->output,
            'additional' => $this->additional,
        ];
    }

    /**
     * Rebuilds the capability snapshot from the normalized cache or transport payload.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            reasoning: is_bool($data['reasoning'] ?? null) ? $data['reasoning'] : null,
            toolCall: is_bool($data['toolCall'] ?? null) ? $data['toolCall'] : null,
            attachment: is_bool($data['attachment'] ?? null) ? $data['attachment'] : null,
            temperature: is_bool($data['temperature'] ?? null) ? $data['temperature'] : null,
            input: is_array($data['input'] ?? null) ? array_filter($data['input'], 'is_bool') : [],
            output: is_array($data['output'] ?? null) ? array_filter($data['output'], 'is_bool') : [],
            additional: is_array($data['additional'] ?? null) ? $data['additional'] : [],
        );
    }
}
