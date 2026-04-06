<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Port;

use App\Enum\ConnectorType;
use App\ValueObject\AgentConfig;
use App\ValueObject\AgentResponse;
use App\ValueObject\Prompt;

/**
 * Hexagonal port for AI agent connectors (API or CLI).
 */
interface AgentPort
{
    /**
     * Sends the built prompt through the connector and returns the normalized agent response.
     */
    public function sendPrompt(Prompt $prompt, AgentConfig $config): AgentResponse;

    /**
     * Checks whether the connector is currently reachable and usable.
     */
    public function healthCheck(): bool;

    /**
     * Indicates whether this implementation supports the given connector type.
     */
    public function supportsConnector(ConnectorType $type): bool;
}
