<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Workflow;

use App\Enum\WorkflowTrigger;
use App\Exception\ValidationException;

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
     *
     * @throws ValidationException with accumulated validation errors
     */
    public static function fromArray(array $data): self
    {
        $errors = [];
        $trigger = null;

        if (isset($data['trigger']) && $data['trigger'] !== '') {
            $trigger = WorkflowTrigger::tryFrom((string) $data['trigger']);
            if ($trigger === null) {
                $errors[] = ['field' => 'trigger', 'code' => 'workflow.validation.trigger_invalid'];
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new self(
            name: isset($data['name']) && $data['name'] !== '' ? (string) $data['name'] : null,
            trigger: $trigger,
            description: isset($data['description']) && $data['description'] !== '' ? (string) $data['description'] : null,
        );
    }
}
