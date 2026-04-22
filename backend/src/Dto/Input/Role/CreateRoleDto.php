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
    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly ?string $description,
    ) {}

    /**
     * @throws \InvalidArgumentException with the validation error key
     */
    public static function fromArray(array $data): self
    {
        if (empty($data['slug']) || empty($data['name'])) {
            throw new \InvalidArgumentException('role.validation.slug_name_required');
        }

        return new self(
            slug: (string) $data['slug'],
            name: (string) $data['name'],
            description: isset($data['description']) && $data['description'] !== '' ? (string) $data['description'] : null,
        );
    }
}
