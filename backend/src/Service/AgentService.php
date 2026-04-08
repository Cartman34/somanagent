<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Service;

use App\Entity\Agent;
use App\Enum\AuditAction;
use App\Enum\ConnectorType;
use App\Repository\AgentRepository;
use App\Repository\RoleRepository;
use App\Repository\TeamRepository;
use App\ValueObject\ConnectorConfig;
use Symfony\Component\Uid\Uuid;

/**
 * Manages agent CRUD, connector configuration, role assignment, and team membership.
 */
class AgentService
{
    /**
     * Initialize the service with its required repositories and dependencies.
     */
    public function __construct(
        private readonly EntityService   $entityService,
        private readonly AgentRepository $agentRepository,
        private readonly RoleRepository  $roleRepository,
        private readonly TeamRepository  $teamRepository,
    ) {}

    /**
     * Create a new agent and persist it with an audit trail.
     */
    public function create(
        string        $name,
        ConnectorType $connector,
        ConnectorConfig $config,
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

        $this->entityService->create($agent, AuditAction::AgentCreated, [
            'name'      => $name,
            'connector' => $connector->value,
        ]);

        return $agent;
    }

    /**
     * Update an existing agent's properties and persist the changes.
     */
    public function update(
        Agent         $agent,
        string        $name,
        ?string       $description,
        ConnectorType $connector,
        ConnectorConfig $config,
        ?string       $roleId,
    ): Agent {
        $agent->setName($name)->setDescription($description)->setConnector($connector)->setConnectorConfig($config);

        $role = $roleId ? $this->roleRepository->find(Uuid::fromString($roleId)) : null;
        $agent->setRole($role);

        $this->entityService->update($agent, AuditAction::AgentUpdated);

        return $agent;
    }

    /**
     * Delete an agent and record the deletion in the audit log.
     */
    public function delete(Agent $agent): void
    {
        $this->entityService->delete($agent, AuditAction::AgentDeleted);
    }

    /**
     * Set the connector type for a single agent and persist the change.
     */
    public function setConnector(Agent $agent, ConnectorType $connector): Agent
    {
        $agent->setConnector($connector);
        $this->entityService->update($agent, AuditAction::AgentUpdated, ['connector' => $connector->value]);

        return $agent;
    }

    /**
     * Apply the given connector type to all agents and return the count of updated agents.
     */
    public function setConnectorForAll(ConnectorType $connector): int
    {
        $count = 0;

        foreach ($this->agentRepository->findAll() as $agent) {
            $agent->setConnector($connector);
            $this->entityService->update($agent, AuditAction::AgentUpdated, ['connector' => $connector->value]);
            ++$count;
        }

        return $count;
    }

    /**
     * @return Agent[]
     */
    public function findAll(): array
    {
        return $this->agentRepository->findAll();
    }

    /**
     * Find an agent by its UUID string, returning null if not found.
     */
    public function findById(string $id): ?Agent
    {
        return $this->agentRepository->find(Uuid::fromString($id));
    }
}
