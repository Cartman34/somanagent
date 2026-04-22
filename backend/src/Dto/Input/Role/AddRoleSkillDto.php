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
    /**
     * @param string $skillId UUID of the skill to add
     */
    public function __construct(
        public readonly string $skillId,
    ) {}

    /**
     * @throws \InvalidArgumentException with a short domain code on validation failure
     */
    public static function fromArray(array $data): self
    {
        if (empty($data['skillId'])) {
            throw new \InvalidArgumentException('skill_id_required');
        }

        return new self(
            skillId: (string) $data['skillId'],
        );
    }
}
