<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Service;

use Sowapps\SoManAgent\Repository\AgentRepository;
use Sowapps\SoManAgent\Repository\RoleRepository;
use Sowapps\SoManAgent\Dto\Input\Agent\CreateAgentDto;
use Sowapps\SoManAgent\Entity\Agent;
use Sowapps\SoManAgent\Enum\AuditAction;
use Sowapps\SoManAgent\Dto\Input\Agent\UpdateAgentDto;
use Sowapps\SoManAgent\Enum\ConnectorType;
use Sowapps\SoManAgent\ValueObject\ConnectorConfig;
use Sowapps\SoManAgent\Exception\ValidationException;
use Sowapps\SoManAgent\Service\EntityService;
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
    ) {}

    /**
     * Create a new agent and persist it with an audit trail.
     */
    public function create(CreateAgentDto $dto): Agent
    {
        $agent = new Agent($dto->name, $dto->connector, $dto->config, $dto->description);

        if ($dto->roleId !== null) {
            $role = $this->roleRepository->find(Uuid::fromString($dto->roleId));
            if ($role !== null) {
                $agent->setRole($role);
            }
        }

        $this->entityService->create($agent, AuditAction::AgentCreated, [
            'name'      => $dto->name,
            'connector' => $dto->connector->value,
        ]);

        return $agent;
    }

    /**
     * Update an existing agent's properties and persist the changes.
     * Implements PATCH semantics: only provided fields are updated.
     *
     * @throws ValidationException when config.model is missing
     */
    public function update(Agent $agent, UpdateAgentDto $dto): Agent
    {
        // Resolve connector: use dto value if provided, otherwise keep existing
        $connector = $agent->getConnector();
        if ($dto->connectorValue !== null) {
            $connector = ConnectorType::from($dto->connectorValue);
        }

        // Resolve config: construct from dto if provided, otherwise keep existing
        $configData = $dto->configData ?? $agent->getConnectorConfig()->toArray();
        $config = ConnectorConfig::fromArray($configData);

        // Validate that model is present
        if (!is_string($config->model) || trim($config->model) === '') {
            throw new ValidationException([
                ['field' => 'config.model', 'code' => 'agent.validation.model_required'],
            ]);
        }

        // Apply PATCH updates
        $agent
            ->setName($dto->name ?? $agent->getName())
            ->setDescription($dto->description ?? $agent->getDescription())
            ->setConnector($connector)
            ->setConnectorConfig($config);

        // Resolve role: use dto value if provided, otherwise keep existing
        if ($dto->roleId !== null) {
            $role = $this->roleRepository->find(Uuid::fromString($dto->roleId));
            $agent->setRole($role);
        }

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
