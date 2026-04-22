<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Project;

use App\Enum\DispatchMode;

/**
 * Input DTO for creating a project.
 */
final class CreateProjectDto
{
    /**
     * @param string       $name                 Project name
     * @param string       $teamId               Team UUID
     * @param ?string      $description          Optional description
     * @param ?string      $repositoryUrl        Optional repository URL
     * @param ?string      $workflowId           Optional workflow UUID
     * @param DispatchMode $dispatchMode         Task dispatch mode
     * @param ?string      $defaultTicketRoleId  Optional default role UUID for tickets
     */
    public function __construct(
        public readonly string $name,
        public readonly string $teamId,
        public readonly ?string $description,
        public readonly ?string $repositoryUrl,
        public readonly ?string $workflowId,
        public readonly DispatchMode $dispatchMode,
        public readonly ?string $defaultTicketRoleId,
    ) {}

    /**
     * @throws \InvalidArgumentException with a short domain code on validation failure
     */
    public static function fromArray(array $data): self
    {
        if (empty($data['name'])) {
            throw new \InvalidArgumentException('name_required');
        }

        if (empty($data['teamId'])) {
            throw new \InvalidArgumentException('team_required');
        }

        return new self(
            name: (string) $data['name'],
            teamId: (string) $data['teamId'],
            description: isset($data['description']) && $data['description'] !== '' ? (string) $data['description'] : null,
            repositoryUrl: isset($data['repositoryUrl']) && $data['repositoryUrl'] !== '' ? (string) $data['repositoryUrl'] : null,
            workflowId: isset($data['workflowId']) && $data['workflowId'] !== '' ? (string) $data['workflowId'] : null,
            dispatchMode: DispatchMode::from($data['dispatchMode'] ?? DispatchMode::Auto->value),
            defaultTicketRoleId: isset($data['defaultTicketRoleId']) && $data['defaultTicketRoleId'] !== '' ? (string) $data['defaultTicketRoleId'] : null,
        );
    }
}
