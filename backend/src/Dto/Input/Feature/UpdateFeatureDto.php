<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Feature;

use App\Enum\FeatureStatus;
use App\Exception\ValidationException;

/**
 * Input DTO for updating a feature (all fields optional).
 */
final class UpdateFeatureDto
{
    /**
     * @param ?string        $name        Updated name or null to keep current
     * @param ?string        $description Updated description or null to keep current
     * @param ?FeatureStatus $status      Updated status or null to keep current
     */
    public function __construct(
        public readonly ?string $name,
        public readonly ?string $description,
        public readonly ?FeatureStatus $status,
    ) {}

    /**
     * Creates an instance from raw request data. No required fields.
     *
     * @throws ValidationException with collected validation errors
     */
    public static function fromArray(array $data): self
    {
        $errors = [];

        $status = null;
        if (isset($data['status']) && $data['status'] !== '') {
            $status = FeatureStatus::tryFrom((string) $data['status']);
            if ($status === null) {
                $errors[] = ['field' => 'status', 'code' => 'feature.validation.status_invalid'];
            }
        }

        if ($errors) {
            throw new ValidationException($errors);
        }

        return new self(
            name: isset($data['name']) && $data['name'] !== '' ? (string) $data['name'] : null,
            description: isset($data['description']) && $data['description'] !== '' ? (string) $data['description'] : null,
            status: $status,
        );
    }
}
