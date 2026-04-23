<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Workflow;

use App\Enum\WorkflowTrigger;

/**
 * Input DTO for updating a workflow (all fields optional).
 */
final class UpdateWorkflowDto
{
    /**
     * @param ?string          $name        Updated name or null to keep current
     * @param ?WorkflowTrigger $trigger     Updated trigger or null to keep current
     * @param ?string          $description Updated description or null to keep current
     */
    public function __construct(
        public readonly ?string $name,
        public readonly ?WorkflowTrigger $trigger,
        public readonly ?string $description,
    ) {}

    /**
     * Creates an instance from raw request data. No required fields.
     * Invalid trigger values are silently ignored (fallback to null).
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: isset($data['name']) && $data['name'] !== '' ? (string) $data['name'] : null,
            trigger: isset($data['trigger']) ? (WorkflowTrigger::tryFrom((string) $data['trigger']) ?? null) : null,
            description: isset($data['description']) && $data['description'] !== '' ? (string) $data['description'] : null,
        );
    }
}
