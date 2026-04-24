<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Feature;

use App\Exception\ValidationException;

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
     * @throws ValidationException with collected validation errors
     */
    public static function fromArray(array $data): self
    {
        $errors = [];

        if (empty($data['name'])) {
            $errors[] = ['field' => 'name', 'code' => 'feature.validation.name_required'];
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new self(
            name: (string) $data['name'],
            description: isset($data['description']) && $data['description'] !== '' ? (string) $data['description'] : null,
        );
    }
}
