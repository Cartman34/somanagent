<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Dto\Input\Team;

use App\Exception\ValidationException;

/**
 * Input DTO for adding an agent to a team.
 */
final class AddTeamAgentDto
{
    /**
     * @param string $agentId UUID of the agent to add
     */
    public function __construct(
        public readonly string $agentId,
    ) {}

    /**
     * Creates an instance from raw request data.
     *
     * @throws ValidationException if validation errors occur
     */
    public static function fromArray(array $data): self
    {
        $errors = [];

        if (empty($data['agentId'])) {
            $errors[] = ['field' => 'agentId', 'code' => 'team.validation.agent_id_required'];
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new self(
            agentId: (string) $data['agentId'],
        );
    }
}
