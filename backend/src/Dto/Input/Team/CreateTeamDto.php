<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Team;

/**
 * Input DTO for creating a team.
 */
final class CreateTeamDto
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $description,
    ) {}

    /**
     * @throws \InvalidArgumentException with the validation error key
     */
    public static function fromArray(array $data): self
    {
        if (empty($data['name'])) {
            throw new \InvalidArgumentException('team.validation.name_required');
        }

        return new self(
            name: (string) $data['name'],
            description: isset($data['description']) && $data['description'] !== '' ? (string) $data['description'] : null,
        );
    }
}
