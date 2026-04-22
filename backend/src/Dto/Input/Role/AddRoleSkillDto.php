<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Role;

/**
 * Input DTO for adding a skill to a role.
 */
final class AddRoleSkillDto
{
    public function __construct(
        public readonly string $skillId,
    ) {}

    /**
     * @throws \InvalidArgumentException with the validation error key
     */
    public static function fromArray(array $data): self
    {
        if (empty($data['skillId'])) {
            throw new \InvalidArgumentException('role.validation.skill_id_required');
        }

        return new self(
            skillId: (string) $data['skillId'],
        );
    }
}
