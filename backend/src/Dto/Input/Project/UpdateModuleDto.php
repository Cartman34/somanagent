<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Project;

/**
 * Input DTO for updating a project module (all fields optional).
 */
final class UpdateModuleDto
{
    /**
     * @param ?string $name          Updated name or null to keep current
     * @param ?string $description   Updated description
     * @param ?string $repositoryUrl Updated repository URL
     * @param ?string $stack         Updated technology stack
     */
    public function __construct(
        public readonly ?string $name,
        public readonly ?string $description,
        public readonly ?string $repositoryUrl,
        public readonly ?string $stack,
    ) {}

    /**
     * Creates an instance from raw request data. No required fields.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: isset($data['name']) && $data['name'] !== '' ? (string) $data['name'] : null,
            description: isset($data['description']) && $data['description'] !== '' ? (string) $data['description'] : null,
            repositoryUrl: isset($data['repositoryUrl']) && $data['repositoryUrl'] !== '' ? (string) $data['repositoryUrl'] : null,
            stack: isset($data['stack']) && $data['stack'] !== '' ? (string) $data['stack'] : null,
        );
    }
}
