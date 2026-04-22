<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Skill;

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
     * @throws \InvalidArgumentException with a short domain code on validation failure
     */
    public static function fromArray(array $data): self
    {
        if (empty($data['source'])) {
            throw new \InvalidArgumentException('source_required');
        }

        return new self(
            source: (string) $data['source'],
        );
    }
}
