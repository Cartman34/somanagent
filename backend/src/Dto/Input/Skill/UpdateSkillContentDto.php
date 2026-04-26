<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Skill;

use App\Exception\ValidationException;

/**
 * Input DTO for updating skill content.
 */
final class UpdateSkillContentDto
{
    /**
     * @param string $content Updated skill content/instructions
     */
    public function __construct(
        public readonly string $content,
    ) {}

    /**
     * @throws ValidationException with validation errors
     */
    public static function fromArray(array $data): self
    {
        $errors = [];

        if (empty($data['content'])) {
            $errors[] = ['field' => 'content', 'code' => 'skill.validation.content_required'];
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new self(
            content: (string) $data['content'],
        );
    }
}
