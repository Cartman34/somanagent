<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Project;

use App\Exception\ValidationException;

/**
 * Input DTO for adding a module to a project.
 */
final class CreateModuleDto
{
    /**
     * @param string  $name          Module name
     * @param ?string $description   Optional description
     * @param ?string $repositoryUrl Optional repository URL
     * @param ?string $stack         Optional technology stack
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $description,
        public readonly ?string $repositoryUrl,
        public readonly ?string $stack,
    ) {}

    /**
     * @throws ValidationException with accumulated validation errors
     */
    public static function fromArray(array $data): self
    {
        $errors = [];

        if (empty($data['name'])) {
            $errors[] = ['field' => 'name', 'code' => 'project.validation.module_name_required'];
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new self(
            name: (string) $data['name'],
            description: isset($data['description']) && $data['description'] !== '' ? (string) $data['description'] : null,
            repositoryUrl: isset($data['repositoryUrl']) && $data['repositoryUrl'] !== '' ? (string) $data['repositoryUrl'] : null,
            stack: isset($data['stack']) && $data['stack'] !== '' ? (string) $data['stack'] : null,
        );
    }
}
