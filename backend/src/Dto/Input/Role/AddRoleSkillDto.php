<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Role;

use App\Exception\ValidationException;

/**
 * Input DTO for adding a skill to a role.
 */
final class AddRoleSkillDto
{
    /**
     * @param string $skillId UUID of the skill to add
     */
    public function __construct(
        public readonly string $skillId,
    ) {}

    /**
     * Creates an instance from raw request data.
     *
     * @throws ValidationException if validation errors occur
     */
    public static function fromArray(array $data): self
    {
        $errors = [];

        if (empty($data['skillId'])) {
            $errors[] = ['field' => 'skillId', 'code' => 'role.validation.skill_id_required'];
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new self(
            skillId: (string) $data['skillId'],
        );
    }
}
