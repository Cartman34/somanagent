<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Skill;

/**
 * Input DTO for creating a custom skill.
 */
final class CreateSkillDto
{
    /**
     * @param string  $slug        Skill unique identifier
     * @param string  $name        Skill display name
     * @param string  $content     Skill content/instructions
     * @param ?string $description Optional description
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $content,
        public readonly ?string $description,
    ) {}

    /**
     * @throws \InvalidArgumentException with a short domain code on validation failure
     */
    public static function fromArray(array $data): self
    {
        if (empty($data['slug']) || empty($data['name']) || empty($data['content'])) {
            throw new \InvalidArgumentException('create_required');
        }

        return new self(
            slug: (string) $data['slug'],
            name: (string) $data['name'],
            content: (string) $data['content'],
            description: isset($data['description']) && $data['description'] !== '' ? (string) $data['description'] : null,
        );
    }
}
