<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Controller;

use App\Enum\ConnectorType;
use App\Repository\AgentRepository;
use App\Service\AgentService;
use App\Service\ApiErrorPayloadFactory;
use App\ValueObject\AgentConfig;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * REST controller managing agents: CRUD, connector configuration, and execution history.
 */
#[Route('/api/agents')]
class AgentController extends AbstractController
{
    /**
     * Initializes the controller with its dependencies.
     */
    public function __construct(
        private readonly AgentService    $agentService,
        private readonly AgentRepository $agentRepository,
        private readonly ApiErrorPayloadFactory $apiErrorPayloadFactory,
    ) {}

    /**
     * Lists all agents.
     *
     * @return JsonResponse Collection of agents with their details
     */
    #[Route('', name: 'agent_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json(array_map(fn($a) => [
            'id'             => (string) $a->getId(),
            'name'           => $a->getName(),
            'description'    => $a->getDescription(),
            'connector'      => $a->getConnector()->value,
            'connectorLabel' => $a->getConnector()->label(),
            'isActive'       => $a->isActive(),
            'role'           => $a->getRole() ? ['id' => (string) $a->getRole()->getId(), 'name' => $a->getRole()->getName()] : null,
            'config'         => $a->getAgentConfig()->toArray(),
            'createdAt'      => $a->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt'      => $a->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ], $this->agentService->findAll()));
    }

    /**
     * Creates a new agent.
     *
     * @param Request $request JSON payload containing name, connector, config, description, and roleId
     * @return JsonResponse Created agent id and name with HTTP 201
     */
    #[Route('', name: 'agent_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = $request->toArray();
        if (empty($data['name'])) {
            return $this->json($this->apiErrorPayloadFactory->create('agent.validation.name_required'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $connector = ConnectorType::from($data['connector'] ?? ConnectorType::ClaudeApi->value);
        $config    = AgentConfig::fromArray($data['config'] ?? ['model' => 'claude-sonnet-4-5']);

        $agent = $this->agentService->create($data['name'], $connector, $config, $data['description'] ?? null, $data['roleId'] ?? null);

        return $this->json(['id' => (string) $agent->getId(), 'name' => $agent->getName()], Response::HTTP_CREATED);
    }

    /**
     * Retrieves a single agent by id.
     *
     * @param string $id Agent UUID
     * @return JsonResponse Agent details or 404 if not found
     */
    #[Route('/{id}', name: 'agent_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $agent = $this->agentService->findById($id);
        if ($agent === null) {
            return $this->json($this->apiErrorPayloadFactory->create('agent.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'id'          => (string) $agent->getId(),
            'name'        => $agent->getName(),
            'description' => $agent->getDescription(),
            'connector'   => $agent->getConnector()->value,
            'connectorLabel' => $agent->getConnector()->label(),
            'isActive'    => $agent->isActive(),
            'role'        => $agent->getRole() ? ['id' => (string) $agent->getRole()->getId(), 'name' => $agent->getRole()->getName()] : null,
            'config'      => $agent->getAgentConfig()->toArray(),
            'createdAt'   => $agent->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ]);
    }

    /**
     * Updates an existing agent.
     *
     * @param string  $id      Agent UUID
     * @param Request $request JSON payload with fields to update
     * @return JsonResponse Updated agent id and name or 404 if not found
     */
    #[Route('/{id}', name: 'agent_update', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $agent = $this->agentService->findById($id);
        if ($agent === null) {
            return $this->json($this->apiErrorPayloadFactory->create('agent.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $data      = $request->toArray();
        $connector = ConnectorType::from($data['connector'] ?? $agent->getConnector()->value);
        $config    = AgentConfig::fromArray($data['config'] ?? $agent->getAgentConfig()->toArray());

        $this->agentService->update($agent, $data['name'] ?? $agent->getName(), $data['description'] ?? null, $connector, $config, $data['roleId'] ?? null);
        return $this->json(['id' => (string) $agent->getId(), 'name' => $agent->getName()]);
    }

    /**
     * Deletes an agent.
     *
     * @param string $id Agent UUID
     * @return JsonResponse No content on success or 404 if not found
     */
    #[Route('/{id}', name: 'agent_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $agent = $this->agentService->findById($id);
        if ($agent === null) {
            return $this->json($this->apiErrorPayloadFactory->create('agent.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $this->agentService->delete($agent);
        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Returns the runtime status of an agent derived from its task and log history.
     *
     * Status values:
     *  - working: the agent has at least one task currently in_progress
     *  - error:   the latest execution-related signal on an assigned task is an error
     *  - idle:    neither of the above
     *
     * @param string $id Agent UUID
     * @return JsonResponse { status: 'working'|'idle'|'error', activeTaskCount: int, lastRuntimeSignal?: array|null }
     */
    #[Route('/{id}/status', name: 'agent_status', methods: ['GET'])]
    public function status(string $id): JsonResponse
    {
        $agent = $this->agentService->findById($id);
        if ($agent === null) {
            return $this->json($this->apiErrorPayloadFactory->create('agent.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $activeTaskCount = $this->agentRepository->countInProgressTasks($agent);
        $lastRuntimeSignal = $this->agentRepository->findLatestRuntimeSignal($agent);

        if ($activeTaskCount > 0) {
            $runtimeStatus = 'working';
        } elseif ($lastRuntimeSignal !== null && $this->agentRepository->isRuntimeErrorAction($lastRuntimeSignal['action'])) {
            $runtimeStatus = 'error';
        } else {
            $runtimeStatus = 'idle';
        }

        return $this->json([
            'status'          => $runtimeStatus,
            'activeTaskCount' => $activeTaskCount,
            'lastRuntimeSignal' => $lastRuntimeSignal === null ? null : [
                'action'    => $lastRuntimeSignal['action'],
                'createdAt' => $lastRuntimeSignal['createdAt']->format(\DateTimeInterface::ATOM),
            ],
        ]);
    }
}
