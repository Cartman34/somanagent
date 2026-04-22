<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Role;

/**
 * Input DTO for creating a role.
 */
final class CreateRoleDto
{
    /**
     * @param string  $slug        Role unique identifier
     * @param string  $name        Role display name
     * @param ?string $description Optional description
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly ?string $description,
    ) {}

    /**
     * @throws \InvalidArgumentException with a short domain code on validation failure
     */
    public static function fromArray(array $data): self
    {
        if (empty($data['slug']) || empty($data['name'])) {
            throw new \InvalidArgumentException('slug_name_required');
        }

        return new self(
            slug: (string) $data['slug'],
            name: (string) $data['name'],
            description: isset($data['description']) && $data['description'] !== '' ? (string) $data['description'] : null,
        );
    }
}
