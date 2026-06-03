<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Dto\Input\Workflow;

use Sowapps\SoManAgent\Enum\WorkflowTrigger;
use Sowapps\SoManAgent\Exception\ValidationException;

/**
 * Input DTO for creating a workflow.
 */
final class CreateWorkflowDto
{
    /**
     * @param string          $name        Workflow display name
     * @param WorkflowTrigger $trigger Activation trigger
     * @param ?string         $description Optional description
     */
    public function __construct(
        public readonly string $name,
        public readonly WorkflowTrigger $trigger,
        public readonly ?string $description,
    ) {}

    /**
     * @param array<string, mixed> $data
     * @throws ValidationException with collected validation errors
     */
    public static function fromArray(array $data): self
    {
        $errors = [];
        $trigger = WorkflowTrigger::Manual;

        if (empty($data['name'])) {
            $errors[] = ['field' => 'name', 'code' => 'workflow.validation.name_required'];
        }

        if (isset($data['trigger']) && $data['trigger'] !== '') {
            $t = WorkflowTrigger::tryFrom((string) $data['trigger']);
            if ($t === null) {
                $errors[] = ['field' => 'trigger', 'code' => 'workflow.validation.trigger_invalid'];
            } else {
                $trigger = $t;
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new self(
            name: (string) $data['name'],
            trigger: $trigger,
            description: isset($data['description']) && $data['description'] !== '' ? (string) $data['description'] : null,
        );
    }
}
