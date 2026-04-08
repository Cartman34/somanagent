<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\ValueObject;

/**
 * Normalized authentication status exposed by a connector.
 */
class ConnectorAuthStatus
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly bool $required,
        public readonly bool $authenticated,
        public readonly string $status,
        public readonly ?string $method = null,
        public readonly ?bool $supportsAccountUsage = null,
        public readonly ?bool $usesAccountUsage = null,
        public readonly ?string $summary = null,
        public readonly ?string $error = null,
        public readonly array $metadata = [],
        public readonly ?string $fixCommand = null,
    ) {}

    /**
     * Returns whether the authentication status satisfies the connector runtime requirement.
     */
    public function isHealthy(): bool
    {
        if (!$this->required) {
            return true;
        }

        return $this->authenticated
            && $this->status === 'ok'
            && ($this->supportsAccountUsage !== true || $this->usesAccountUsage === true);
    }

    /**
     * Converts this auth snapshot into the shared connector health-check format.
     */
    public function toHealthCheckResult(): ConnectorHealthCheckResult
    {
        return new ConnectorHealthCheckResult(
            name: 'auth',
            status: $this->isHealthy() ? 'ok' : ($this->required ? 'degraded' : 'skipped'),
            summary: $this->summary ?? $this->error,
            fixCommand: $this->fixCommand,
            details: $this->toArray(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'required' => $this->required,
            'authenticated' => $this->authenticated,
            'status' => $this->status,
            'method' => $this->method,
            'supportsAccountUsage' => $this->supportsAccountUsage,
            'usesAccountUsage' => $this->usesAccountUsage,
            'summary' => $this->summary,
            'error' => $this->error,
            'metadata' => $this->metadata,
            'fixCommand' => $this->fixCommand,
        ];
    }

    /**
     * Returns a skipped auth snapshot for connectors without a dedicated auth probe.
     */
    public static function skipped(?string $summary = null): self
    {
        return new self(
            required: false,
            authenticated: true,
            status: 'skipped',
            summary: $summary ?? 'This connector does not require a dedicated authentication check.',
        );
    }
}
