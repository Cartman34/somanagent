<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Skill;

use App\Exception\ValidationException;

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
     * @throws ValidationException with validation errors
     */
    public static function fromArray(array $data): self
    {
        $errors = [];

        if (empty($data['slug'])) {
            $errors[] = ['field' => 'slug', 'code' => 'skill.validation.create_required'];
        }

        if (empty($data['name'])) {
            $errors[] = ['field' => 'name', 'code' => 'skill.validation.create_required'];
        }

        if (empty($data['content'])) {
            $errors[] = ['field' => 'content', 'code' => 'skill.validation.create_required'];
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new self(
            slug: (string) $data['slug'],
            name: (string) $data['name'],
            content: (string) $data['content'],
            description: isset($data['description']) && $data['description'] !== '' ? (string) $data['description'] : null,
        );
    }
}
