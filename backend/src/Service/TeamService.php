<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Service;

use App\Entity\Agent;
use App\Entity\Team;
use App\Enum\AuditAction;
use App\Repository\AgentRepository;
use App\Repository\TeamRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class TeamService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TeamRepository         $teamRepository,
        private readonly AgentRepository        $agentRepository,
        private readonly AuditService           $audit,
    ) {}

    public function create(string $name, ?string $description = null): Team
    {
        $team = new Team($name, $description);
        $this->em->persist($team);
        $this->em->flush();
        $this->audit->log(AuditAction::TeamCreated, 'Team', (string) $team->getId(), ['name' => $name]);
        return $team;
    }

    public function update(Team $team, string $name, ?string $description): Team
    {
        $team->setName($name)->setDescription($description);
        $this->em->flush();
        $this->audit->log(AuditAction::TeamUpdated, 'Team', (string) $team->getId());
        return $team;
    }

    public function delete(Team $team): void
    {
        $id = (string) $team->getId();
        $this->em->remove($team);
        $this->em->flush();
        $this->audit->log(AuditAction::TeamDeleted, 'Team', $id);
    }

    public function addAgent(Team $team, Agent $agent): void
    {
        $team->addAgent($agent);
        $this->em->flush();
        $this->audit->log(AuditAction::TeamAgentAdded, 'Team', (string) $team->getId(), [
            'agent' => (string) $agent->getId(),
            'agentName' => $agent->getName(),
        ]);
    }

    public function removeAgent(Team $team, Agent $agent): void
    {
        $team->removeAgent($agent);
        $this->em->flush();
        $this->audit->log(AuditAction::TeamAgentRemoved, 'Team', (string) $team->getId(), [
            'agent' => (string) $agent->getId(),
        ]);
    }

    /** @return Team[] */
    public function findAll(): array
    {
        return $this->teamRepository->findAll();
    }

    public function findById(string $id): ?Team
    {
        return $this->teamRepository->find(Uuid::fromString($id));
    }

    public function findAgentById(string $id): ?Agent
    {
        return $this->agentRepository->find(Uuid::fromString($id));
    }
}
