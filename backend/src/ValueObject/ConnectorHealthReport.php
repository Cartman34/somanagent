<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\ValueObject;

use App\Enum\ConnectorType;

/**
 * Aggregated health report returned by one connector.
 */
final readonly class ConnectorHealthReport
{
    /**
     * Builds the aggregated health report returned by one connector.
     */
    public function __construct(
        public ConnectorType $connector,
        public ConnectorHealthChecks $checks,
    ) {}

    /**
     * Returns whether none of the aggregated checks is degraded.
     */
    public function isHealthy(): bool
    {
        return $this->checks->firstDegraded() === null;
    }

    /**
     * Returns the first degraded summary found in the aggregated checks.
     */
    public function overallSummary(): ?string
    {
        return $this->checks->firstDegraded()?->summary;
    }

    /**
     * Returns the first remediation command found in the aggregated checks.
     */
    public function overallFixCommand(): ?string
    {
        return $this->checks->firstDegraded()?->fixCommand;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'connector' => $this->connector->value,
            'label' => $this->connector->label(),
            'status' => $this->isHealthy() ? 'ok' : 'degraded',
            'summary' => $this->overallSummary(),
            'fixCommand' => $this->overallFixCommand(),
            'checks' => $this->checks->toArray(),
        ];
    }
}
