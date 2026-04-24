<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Team;

use App\Exception\ValidationException;

/**
 * Input DTO for creating a team.
 */
final class CreateTeamDto
{
    /**
     * @param string  $name        Team display name
     * @param ?string $description Optional description
     */
    public function __construct(
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

        if (empty($data['name'])) {
            $errors[] = ['field' => 'name', 'code' => 'team.validation.name_required'];
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new self(
            name: (string) $data['name'],
            description: isset($data['description']) && $data['description'] !== '' ? (string) $data['description'] : null,
        );
    }
}
