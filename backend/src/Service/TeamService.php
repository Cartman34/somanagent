<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Service;

use App\Dto\Input\Team\CreateTeamDto;
use App\Dto\Input\Team\UpdateTeamDto;
use App\Entity\Agent;
use App\Entity\Team;
use App\Enum\AuditAction;
use App\Repository\AgentRepository;
use App\Repository\TeamRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Manages teams: CRUD, agent membership, and project assignment.
 */
class TeamService
{
    /**
     * Initialize the service with its required repositories and entity service.
     */
    public function __construct(
        private readonly EntityService  $entityService,
        private readonly TeamRepository $teamRepository,
        private readonly AgentRepository $agentRepository,
    ) {}

    /**
     * Create a new team and persist it with an audit trail.
     */
    public function create(CreateTeamDto $dto): Team
    {
        $team = new Team($dto->name, $dto->description);
        $this->entityService->create($team, AuditAction::TeamCreated, ['name' => $dto->name]);
        return $team;
    }

    /**
     * Update a team's name and description, then persist the changes.
     */
    public function update(Team $team, UpdateTeamDto $dto): Team
    {
        $team->setName($dto->name ?? $team->getName())->setDescription($dto->description ?? $team->getDescription());
        $this->entityService->update($team, AuditAction::TeamUpdated);
        return $team;
    }

    /**
     * Delete a team and record the deletion in the audit log.
     */
    public function delete(Team $team): void
    {
        $this->entityService->delete($team, AuditAction::TeamDeleted);
    }

    /**
     * Add an agent to a team and record the change in the audit log.
     */
    public function addAgent(Team $team, Agent $agent): void
    {
        $team->addAgent($agent);
        $this->entityService->update($team, AuditAction::TeamAgentAdded, [
            'agent'     => (string) $agent->getId(),
            'agentName' => $agent->getName(),
        ]);
    }

    /**
     * Remove an agent from a team and record the change in the audit log.
     */
    public function removeAgent(Team $team, Agent $agent): void
    {
        $team->removeAgent($agent);
        $this->entityService->update($team, AuditAction::TeamAgentRemoved, [
            'agent' => (string) $agent->getId(),
        ]);
    }

    /**
     * @return Team[]
     */
    public function findAll(): array
    {
        return $this->teamRepository->findAll();
    }

    /**
     * Find a team by its UUID string, returning null if not found.
     */
    public function findById(string $id): ?Team
    {
        return $this->teamRepository->find(Uuid::fromString($id));
    }

    /**
     * Find an agent by its UUID string, returning null if not found.
     */
    public function findAgentById(string $id): ?Agent
    {
        return $this->agentRepository->find(Uuid::fromString($id));
    }
}
