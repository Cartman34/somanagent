<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Workflow;

use App\Enum\WorkflowTrigger;
use App\Exception\ValidationException;

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
     * @throws ValidationException with collected validation errors
     */
    public static function fromArray(array $data): self
    {
        $errors = [];

        if (empty($data['name'])) {
            $errors[] = ['field' => 'name', 'code' => 'workflow.validation.name_required'];
        }

        if ($errors) {
            throw new ValidationException($errors);
        }

        return new self(
            name: (string) $data['name'],
            trigger: WorkflowTrigger::tryFrom((string) ($data['trigger'] ?? 'manual')) ?? WorkflowTrigger::Manual,
            description: isset($data['description']) && $data['description'] !== '' ? (string) $data['description'] : null,
        );
    }
}
