<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\ValueObject;

/**
 * Immutable pricing snapshot for one model when the provider exposes costs.
 */
final readonly class AgentModelPricing
{
    /**
     * Builds the immutable pricing snapshot used by connector model catalogs.
     */
    public function __construct(
        public ?float $input = null,
        public ?float $output = null,
        public ?float $cacheRead = null,
        public ?float $cacheWrite = null,
        public ?bool $isFree = null,
    ) {}

    /**
     * Returns the normalized pricing payload exposed by the API and CLI layers.
     *
     * @return array<string, float|bool|null>
     */
    public function toArray(): array
    {
        return [
            'input' => $this->input,
            'output' => $this->output,
            'cacheRead' => $this->cacheRead,
            'cacheWrite' => $this->cacheWrite,
            'isFree' => $this->isFree,
        ];
    }

    /**
     * Rebuilds the pricing snapshot from the normalized cache or transport payload.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            input: is_numeric($data['input'] ?? null) ? (float) $data['input'] : null,
            output: is_numeric($data['output'] ?? null) ? (float) $data['output'] : null,
            cacheRead: is_numeric($data['cacheRead'] ?? null) ? (float) $data['cacheRead'] : null,
            cacheWrite: is_numeric($data['cacheWrite'] ?? null) ? (float) $data['cacheWrite'] : null,
            isFree: is_bool($data['isFree'] ?? null) ? $data['isFree'] : null,
        );
    }
}
