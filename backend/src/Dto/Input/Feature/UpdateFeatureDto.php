<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Feature;

use App\Enum\FeatureStatus;

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
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: isset($data['name']) && $data['name'] !== '' ? (string) $data['name'] : null,
            description: isset($data['description']) && $data['description'] !== '' ? (string) $data['description'] : null,
            status: isset($data['status']) ? FeatureStatus::from((string) $data['status']) : null,
        );
    }
}
