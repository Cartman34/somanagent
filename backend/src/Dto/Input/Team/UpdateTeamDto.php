<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Team;

/**
 * Input DTO for updating a team (all fields optional).
 */
final class UpdateTeamDto
{
    /**
     * @param ?string $name        Updated name or null to keep current
     * @param ?string $description Updated description or null to keep current
     */
    public function __construct(
        public readonly ?string $name,
        public readonly ?string $description,
    ) {}

    /**
     * Creates an instance from raw request data. No required fields.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: isset($data['name']) && $data['name'] !== '' ? (string) $data['name'] : null,
            description: isset($data['description']) && $data['description'] !== '' ? (string) $data['description'] : null,
        );
    }
}
