<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Port;

use App\Enum\ConnectorType;
use App\ValueObject\ConnectorAuthStatus;
use App\ValueObject\ConnectorConfig;
use App\ValueObject\ConnectorHealthReport;
use App\ValueObject\ConnectorRequest;
use App\ValueObject\ConnectorResponse;
use App\ValueObject\AgentModelInfo;

/**
 * Hexagonal contract for low-level AI connectors.
 */
interface ConnectorInterface
{
    /**
     * Sends one normalized request through the connector runtime.
     */
    public function sendRequest(ConnectorRequest $request, ConnectorConfig $config): ConnectorResponse;

    /**
     * Returns a typed health report made of shared connector checks.
     *
     * When $deep is false (default), the prompt_test check is skipped or served from cache.
     * When $deep is true, a real prompt is sent to verify end-to-end connectivity.
     */
    public function checkHealth(?ConnectorConfig $config = null, bool $deep = false): ConnectorHealthReport;

    /**
     * Returns the normalized authentication status used by the health report.
     */
    public function getAuthenticationStatus(): ConnectorAuthStatus;

    /**
     * Indicates whether this implementation supports the given connector type.
     */
    public function supportsConnector(ConnectorType $type): bool;

    /**
     * Returns whether this connector can discover models at runtime.
     */
    public function supportsModelDiscovery(): bool;

    /**
     * Discovers the normalized models exposed by this connector.
     *
     * @return AgentModelInfo[]
     */
    public function discoverModels(): array;
}
