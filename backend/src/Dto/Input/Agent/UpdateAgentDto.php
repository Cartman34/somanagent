<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Agent;

use App\Enum\ConnectorType;
use App\Exception\ValidationException;

/**
 * Input DTO for updating an agent (all fields optional).
 */
final class UpdateAgentDto
{
    /**
     * @param ?string  $name           Updated name or null to keep current
     * @param ?string  $connectorValue Raw connector string value or null to keep current
     * @param ?array   $configData     Raw config array or null to keep current
     * @param ?string  $description    Updated description or null to keep current
     * @param ?string  $roleId         Updated role UUID or null to keep current
     */
    public function __construct(
        public readonly ?string $name,
        public readonly ?string $connectorValue,
        public readonly ?array $configData,
        public readonly ?string $description,
        public readonly ?string $roleId,
    ) {}

    /**
     * @throws ValidationException when the connector value is provided but invalid
     */
    public static function fromArray(array $data): self
    {
        $errors = [];
        $connectorValue = null;

        if (isset($data['connector'])) {
            $connectorValue = (string) $data['connector'];
            if (ConnectorType::tryFrom($connectorValue) === null) {
                $errors[] = ['field' => 'connector', 'code' => 'agent.validation.connector_invalid'];
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new self(
            name: isset($data['name']) && $data['name'] !== '' ? (string) $data['name'] : null,
            connectorValue: $connectorValue,
            configData: is_array($data['config'] ?? null) ? $data['config'] : null,
            description: isset($data['description']) && $data['description'] !== '' ? (string) $data['description'] : null,
            roleId: isset($data['roleId']) && $data['roleId'] !== '' ? (string) $data['roleId'] : null,
        );
    }
}
