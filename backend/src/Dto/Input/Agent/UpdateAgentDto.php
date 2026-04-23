<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Agent;

/**
 * Input DTO for updating an agent (all fields optional).
 * All fields are optional and no validation errors are thrown.
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
     * Creates an instance from raw request data. No required fields.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: isset($data['name']) && $data['name'] !== '' ? (string) $data['name'] : null,
            connectorValue: isset($data['connector']) ? (string) $data['connector'] : null,
            configData: is_array($data['config'] ?? null) ? $data['config'] : null,
            description: isset($data['description']) && $data['description'] !== '' ? (string) $data['description'] : null,
            roleId: isset($data['roleId']) && $data['roleId'] !== '' ? (string) $data['roleId'] : null,
        );
    }
}
