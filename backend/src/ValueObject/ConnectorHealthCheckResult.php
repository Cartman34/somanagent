<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\ValueObject;

/**
 * One normalized connector health check result.
 */
final readonly class ConnectorHealthCheckResult
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public string $name,
        public string $status,
        public ?string $summary = null,
        public ?string $fixCommand = null,
        public array $details = [],
    ) {}

    /**
     * Returns whether this single check succeeded.
     */
    public function isHealthy(): bool
    {
        return $this->status === 'ok';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'status' => $this->status,
            'summary' => $this->summary,
            'fixCommand' => $this->fixCommand,
            'details' => $this->details,
        ];
    }
}
