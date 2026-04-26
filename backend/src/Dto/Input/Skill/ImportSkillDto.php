<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Skill;

use App\Exception\ValidationException;

/**
 * Input DTO for importing a skill from the registry.
 */
final class ImportSkillDto
{
    /**
     * @param string $source Registry source identifier
     */
    public function __construct(
        public readonly string $source,
    ) {}

    /**
     * @throws ValidationException with validation errors
     */
    public static function fromArray(array $data): self
    {
        $errors = [];

        if (empty($data['source'])) {
            $errors[] = ['field' => 'source', 'code' => 'skill.validation.source_required'];
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new self(
            source: (string) $data['source'],
        );
    }
}
