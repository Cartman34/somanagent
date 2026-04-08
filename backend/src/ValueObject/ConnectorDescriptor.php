<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\ValueObject;

use App\Enum\ConnectorType;

/**
 * Runtime descriptor for one registered connector.
 */
final readonly class ConnectorDescriptor
{
    /**
     * Builds the runtime descriptor exposed for one registered connector.
     */
    public function __construct(
        public ConnectorType $connector,
        public string $connectorClass,
        public ConnectorHealthReport $health,
        public ConnectorAuthStatus $authentication,
        public bool $supportsModelDiscovery,
    ) {}

    /**
     * Returns whether the aggregated connector health report is healthy.
     */
    public function isOverallHealthy(): bool
    {
        return $this->health->isHealthy();
    }

    /**
     * Returns the first degraded summary reported by the connector health report.
     */
    public function overallReason(): ?string
    {
        return $this->health->overallSummary();
    }

    /**
     * Returns the first remediation command exposed by the connector health report.
     */
    public function overallFixCommand(): ?string
    {
        return $this->health->overallFixCommand();
    }
}
