<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Role;

use App\Exception\ValidationException;

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
     * Creates an instance from raw request data.
     *
     * @throws ValidationException if validation errors occur
     */
    public static function fromArray(array $data): self
    {
        $errors = [];

        if (empty($data['slug'])) {
            $errors[] = ['field' => 'slug', 'code' => 'role.validation.slug_required'];
        }

        if (empty($data['name'])) {
            $errors[] = ['field' => 'name', 'code' => 'role.validation.name_required'];
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new self(
            slug: (string) $data['slug'],
            name: (string) $data['name'],
            description: isset($data['description']) && $data['description'] !== '' ? (string) $data['description'] : null,
        );
    }
}
