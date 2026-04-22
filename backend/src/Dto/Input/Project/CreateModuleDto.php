<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Project;

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
     * @throws \InvalidArgumentException with a short domain code on validation failure
     */
    public static function fromArray(array $data): self
    {
        if (empty($data['name'])) {
            throw new \InvalidArgumentException('name_required');
        }

        return new self(
            name: (string) $data['name'],
            description: isset($data['description']) && $data['description'] !== '' ? (string) $data['description'] : null,
            repositoryUrl: isset($data['repositoryUrl']) && $data['repositoryUrl'] !== '' ? (string) $data['repositoryUrl'] : null,
            stack: isset($data['stack']) && $data['stack'] !== '' ? (string) $data['stack'] : null,
        );
    }
}
