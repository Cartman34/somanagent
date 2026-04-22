<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Agent;

use App\Enum\ConnectorType;
use App\ValueObject\ConnectorConfig;

/**
 * Input DTO for creating an agent.
 */
final class CreateAgentDto
{
    /**
     * @param string         $name        Agent display name
     * @param ConnectorType  $connector   Connector type
     * @param ConnectorConfig $config     Connector configuration
     * @param ?string        $description Optional description
     * @param ?string        $roleId      Optional role UUID
     */
    public function __construct(
        public readonly string $name,
        public readonly ConnectorType $connector,
        public readonly ConnectorConfig $config,
        public readonly ?string $description,
        public readonly ?string $roleId,
    ) {}

    /**
     * @throws \InvalidArgumentException with a short domain code on validation failure
     */
    public static function fromArray(array $data): self
    {
        if (empty($data['name'])) {
            throw new \InvalidArgumentException('name_required');
        }

        if (!is_array($data['config'] ?? null) || !is_string($data['config']['model'] ?? null) || trim($data['config']['model']) === '') {
            throw new \InvalidArgumentException('model_required');
        }

        return new self(
            name: (string) $data['name'],
            connector: ConnectorType::from($data['connector'] ?? ConnectorType::ClaudeApi->value),
            config: ConnectorConfig::fromArray($data['config']),
            description: isset($data['description']) && $data['description'] !== '' ? (string) $data['description'] : null,
            roleId: isset($data['roleId']) && $data['roleId'] !== '' ? (string) $data['roleId'] : null,
        );
    }
}
