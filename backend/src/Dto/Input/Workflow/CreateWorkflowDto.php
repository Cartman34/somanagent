<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Workflow;

use App\Enum\WorkflowTrigger;

/**
 * Input DTO for creating a workflow.
 */
final class CreateWorkflowDto
{
    /**
     * @param string          $name        Workflow display name
     * @param WorkflowTrigger $trigger     Activation trigger
     * @param ?string         $description Optional description
     */
    public function __construct(
        public readonly string $name,
        public readonly WorkflowTrigger $trigger,
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
            trigger: WorkflowTrigger::tryFrom((string) ($data['trigger'] ?? 'manual')) ?? WorkflowTrigger::Manual,
            description: isset($data['description']) && $data['description'] !== '' ? (string) $data['description'] : null,
        );
    }
}
