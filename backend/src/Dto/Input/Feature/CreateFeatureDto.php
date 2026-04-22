<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Feature;

/**
 * Input DTO for creating a feature.
 */
final class CreateFeatureDto
{
    /**
     * @param string  $name        Feature display name
     * @param ?string $description Optional description
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $description,
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
        );
    }
}
