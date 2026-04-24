<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Project;

use App\Enum\DispatchMode;
use App\Exception\ValidationException;

/**
 * Input DTO for creating a project.
 */
final class CreateProjectDto
{
    /**
     * @param string       $name                 Project name
     * @param string       $teamId               Team UUID
     * @param string       $workflowId           Workflow UUID
     * @param ?string      $description          Optional description
     * @param ?string      $repositoryUrl        Optional repository URL
     * @param DispatchMode $dispatchMode         Task dispatch mode
     * @param ?string      $defaultTicketRoleId  Optional default role UUID for tickets
     */
    public function __construct(
        public readonly string $name,
        public readonly string $teamId,
        public readonly string $workflowId,
        public readonly ?string $description,
        public readonly ?string $repositoryUrl,
        public readonly DispatchMode $dispatchMode,
        public readonly ?string $defaultTicketRoleId,
    ) {}

    /**
     * @throws ValidationException with accumulated validation errors
     */
    public static function fromArray(array $data): self
    {
        $errors = [];

        if (empty($data['name'])) {
            $errors[] = ['field' => 'name', 'code' => 'project.validation.name_required'];
        }

        if (empty($data['teamId'])) {
            $errors[] = ['field' => 'teamId', 'code' => 'project.validation.team_required'];
        }

        if (empty($data['workflowId'])) {
            $errors[] = ['field' => 'workflowId', 'code' => 'project.validation.workflow_required'];
        }

        $dispatchMode = DispatchMode::Auto;
        if (isset($data['dispatchMode']) && $data['dispatchMode'] !== '') {
            $dm = DispatchMode::tryFrom((string) $data['dispatchMode']);
            if ($dm === null) {
                $errors[] = ['field' => 'dispatchMode', 'code' => 'project.validation.dispatch_mode_invalid'];
            } else {
                $dispatchMode = $dm;
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new self(
            name: (string) $data['name'],
            teamId: (string) $data['teamId'],
            workflowId: (string) $data['workflowId'],
            description: isset($data['description']) && $data['description'] !== '' ? (string) $data['description'] : null,
            repositoryUrl: isset($data['repositoryUrl']) && $data['repositoryUrl'] !== '' ? (string) $data['repositoryUrl'] : null,
            dispatchMode: $dispatchMode,
            defaultTicketRoleId: isset($data['defaultTicketRoleId']) && $data['defaultTicketRoleId'] !== '' ? (string) $data['defaultTicketRoleId'] : null,
        );
    }
}
