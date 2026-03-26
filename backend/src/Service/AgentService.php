<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Agent;
use App\Entity\Team;
use App\Enum\AuditAction;
use App\Enum\ConnectorType;
use App\Repository\AgentRepository;
use App\Repository\RoleRepository;
use App\Repository\TeamRepository;
use App\ValueObject\AgentConfig;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class AgentService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AgentRepository        $agentRepository,
        private readonly RoleRepository         $roleRepository,
        private readonly TeamRepository         $teamRepository,
        private readonly AuditService           $audit,
    ) {}

    public function create(
        string        $name,
        ConnectorType $connector,
        AgentConfig   $config,
        ?string       $description = null,
        ?string       $roleId      = null,
    ): Agent {
        $agent = new Agent($name, $connector, $config, $description);

        if ($roleId !== null) {
            $role = $this->roleRepository->find(Uuid::fromString($roleId));
            if ($role !== null) {
                $agent->setRole($role);
            }
        }

        $this->em->persist($agent);
        $this->em->flush();
        $this->audit->log(AuditAction::AgentCreated, 'Agent', (string) $agent->getId(), [
            'name'      => $name,
            'connector' => $connector->value,
        ]);
        return $agent;
    }

    public function update(
        Agent         $agent,
        string        $name,
        ?string       $description,
        ConnectorType $connector,
        AgentConfig   $config,
        ?string       $roleId,
    ): Agent {
        $agent->setName($name)->setDescription($description)->setConnector($connector)->setAgentConfig($config);

        $role = $roleId ? $this->roleRepository->find(Uuid::fromString($roleId)) : null;
        $agent->setRole($role);

        $this->em->flush();
        $this->audit->log(AuditAction::AgentUpdated, 'Agent', (string) $agent->getId());
        return $agent;
    }

    public function delete(Agent $agent): void
    {
        $id = (string) $agent->getId();
        $this->em->remove($agent);
        $this->em->flush();
        $this->audit->log(AuditAction::AgentDeleted, 'Agent', $id);
    }

    /** @return Agent[] */
    public function findAll(): array
    {
        return $this->agentRepository->findAll();
    }

    public function findById(string $id): ?Agent
    {
        return $this->agentRepository->find(Uuid::fromString($id));
    }
}
