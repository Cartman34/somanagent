<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Team;

/**
 * Input DTO for adding an agent to a team.
 */
final class AddTeamAgentDto
{
    public function __construct(
        public readonly string $agentId,
    ) {}

    /**
     * @throws \InvalidArgumentException with the validation error key
     */
    public static function fromArray(array $data): self
    {
        if (empty($data['agentId'])) {
            throw new \InvalidArgumentException('team.validation.agent_id_required');
        }

        return new self(
            agentId: (string) $data['agentId'],
        );
    }
}
