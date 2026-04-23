<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Agent;

use App\Enum\ConnectorType;
use App\Exception\ValidationException;
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
     * @throws ValidationException with collected validation errors
     */
    public static function fromArray(array $data): self
    {
        $errors = [];

        if (empty($data['name'])) {
            $errors[] = ['field' => 'name', 'code' => 'agent.validation.name_required'];
        }

        if (!is_array($data['config'] ?? null) || !is_string($data['config']['model'] ?? null) || trim($data['config']['model']) === '') {
            $errors[] = ['field' => 'config.model', 'code' => 'agent.validation.model_required'];
        }

        $connector = ConnectorType::ClaudeApi;
        if (isset($data['connector']) && $data['connector'] !== '') {
            $connectorEnum = ConnectorType::tryFrom((string) $data['connector']);
            if ($connectorEnum === null) {
                $errors[] = ['field' => 'connector', 'code' => 'agent.validation.connector_invalid'];
            } else {
                $connector = $connectorEnum;
            }
        }

        if ($errors) {
            throw new ValidationException($errors);
        }

        return new self(
            name: (string) $data['name'],
            connector: $connector,
            config: ConnectorConfig::fromArray($data['config']),
            description: isset($data['description']) && $data['description'] !== '' ? (string) $data['description'] : null,
            roleId: isset($data['roleId']) && $data['roleId'] !== '' ? (string) $data['roleId'] : null,
        );
    }
}
