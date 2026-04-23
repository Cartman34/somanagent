<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Project;

use App\Enum\DispatchMode;
use App\Exception\ValidationException;

/**
 * Input DTO for updating a project (all fields optional).
 *
 * For PATCH requests, null values indicate "not provided" and the controller
 * will apply fallback logic using current entity values.
 */
final class UpdateProjectDto
{
    /**
     * @param ?string      $name                 Updated name or null to keep current
     * @param ?string      $description          Updated description or null to keep current
     * @param ?string      $repositoryUrl        Updated repository URL or null to keep current
     * @param ?string      $teamId               Team UUID or null to keep current
     * @param ?string      $workflowId           Workflow UUID or null to keep current
     * @param ?string      $dispatchModeValue    Raw dispatch mode string or null to keep current
     * @param ?string      $defaultTicketRoleId  Default role UUID or null to keep current
     */
    public function __construct(
        public readonly ?string $name,
        public readonly ?string $description,
        public readonly ?string $repositoryUrl,
        public readonly ?string $teamId,
        public readonly ?string $workflowId,
        public readonly ?string $dispatchModeValue,
        public readonly ?string $defaultTicketRoleId,
    ) {}

    /**
     * Creates an instance from raw request data. No required fields.
     *
     * @throws ValidationException with accumulated validation errors
     */
    public static function fromArray(array $data): self
    {
        $errors = [];
        $dispatchModeValue = null;

        if (isset($data['dispatchMode']) && $data['dispatchMode'] !== '') {
            $dm = DispatchMode::tryFrom((string) $data['dispatchMode']);
            if ($dm === null) {
                $errors[] = ['field' => 'dispatchMode', 'code' => 'project.validation.dispatch_mode_invalid'];
            } else {
                $dispatchModeValue = (string) $data['dispatchMode'];
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new self(
            name: isset($data['name']) && $data['name'] !== '' ? (string) $data['name'] : null,
            description: isset($data['description']) && $data['description'] !== '' ? (string) $data['description'] : null,
            repositoryUrl: isset($data['repositoryUrl']) && $data['repositoryUrl'] !== '' ? (string) $data['repositoryUrl'] : null,
            teamId: isset($data['teamId']) && $data['teamId'] !== '' ? (string) $data['teamId'] : null,
            workflowId: isset($data['workflowId']) && $data['workflowId'] !== '' ? (string) $data['workflowId'] : null,
            dispatchModeValue: $dispatchModeValue,
            defaultTicketRoleId: isset($data['defaultTicketRoleId']) && $data['defaultTicketRoleId'] !== '' ? (string) $data['defaultTicketRoleId'] : null,
        );
    }
}
