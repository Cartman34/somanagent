<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Project;

/**
 * Input DTO for updating a project.
 *
 * For ID fields (teamId, workflowId, defaultTicketRoleId), a boolean flag indicates
 * whether the key was present in the request, allowing distinction between
 * "not provided (keep current)" and "provided as null/empty (remove)".
 */
final class UpdateProjectDto
{
    /**
     * @param ?string $name                  Updated name or null to keep current
     * @param ?string $description           Updated description
     * @param ?string $repositoryUrl         Updated repository URL
     * @param bool    $hasTeamId             Whether teamId was present in request
     * @param ?string $teamId                Team UUID or null to remove
     * @param bool    $hasWorkflowId         Whether workflowId was present in request
     * @param ?string $workflowId            Workflow UUID or null to remove
     * @param ?string $dispatchModeValue     Raw dispatch mode string or null to keep current
     * @param bool    $hasDefaultTicketRoleId Whether defaultTicketRoleId was present in request
     * @param ?string $defaultTicketRoleId   Default role UUID or null to remove
     */
    public function __construct(
        public readonly ?string $name,
        public readonly ?string $description,
        public readonly ?string $repositoryUrl,
        public readonly bool $hasTeamId,
        public readonly ?string $teamId,
        public readonly bool $hasWorkflowId,
        public readonly ?string $workflowId,
        public readonly ?string $dispatchModeValue,
        public readonly bool $hasDefaultTicketRoleId,
        public readonly ?string $defaultTicketRoleId,
    ) {}

    /**
     * Creates an instance from raw request data. No required fields.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: isset($data['name']) && $data['name'] !== '' ? (string) $data['name'] : null,
            description: isset($data['description']) && $data['description'] !== '' ? (string) $data['description'] : null,
            repositoryUrl: isset($data['repositoryUrl']) && $data['repositoryUrl'] !== '' ? (string) $data['repositoryUrl'] : null,
            hasTeamId: array_key_exists('teamId', $data),
            teamId: array_key_exists('teamId', $data) ? ($data['teamId'] ?: null) : null,
            hasWorkflowId: array_key_exists('workflowId', $data),
            workflowId: array_key_exists('workflowId', $data) ? ($data['workflowId'] ?: null) : null,
            dispatchModeValue: isset($data['dispatchMode']) ? (string) $data['dispatchMode'] : null,
            hasDefaultTicketRoleId: array_key_exists('defaultTicketRoleId', $data),
            defaultTicketRoleId: array_key_exists('defaultTicketRoleId', $data) ? ($data['defaultTicketRoleId'] ?: null) : null,
        );
    }
}
