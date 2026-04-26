<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Controller;

use App\Dto\Input\Agent\CreateAgentDto;
use App\Dto\Input\Agent\UpdateAgentDto;
use App\Entity\AgentTaskExecution;
use App\Entity\AgentTaskExecutionAttempt;
use App\Enum\ConnectorType;
use App\Exception\ValidationException;
use App\Repository\AgentRepository;
use App\Repository\AgentTaskExecutionRepository;
use App\Service\AgentModelCatalogService;
use App\Service\AgentService;
use App\Service\ApiErrorPayloadFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * REST controller managing agents: CRUD, connector configuration, and execution history.
 */
#[Route('/api/agents')]
class AgentController extends AbstractApiController
{
    /**
     * Initializes the controller with its dependencies.
     */
    public function __construct(
        private readonly AgentService    $agentService,
        private readonly AgentRepository $agentRepository,
        private readonly AgentTaskExecutionRepository $agentTaskExecutionRepository,
        private readonly AgentModelCatalogService $agentModelCatalogService,
        ApiErrorPayloadFactory $apiErrorPayloadFactory,
    ) {
        parent::__construct($apiErrorPayloadFactory);
    }

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
            'config'         => $a->getConnectorConfig()->toArray(),
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
        $dto = $this->tryParseDto(fn() => CreateAgentDto::fromArray($request->toArray()));
        if ($dto instanceof JsonResponse) {
            return $dto;
        }

        $agent = $this->agentService->create($dto);

        return $this->json(['id' => (string) $agent->getId(), 'name' => $agent->getName()], Response::HTTP_CREATED);
    }

    /**
     * Retrieves a single agent by id.
     *
     * @param string $id Agent UUID
     * @return JsonResponse Agent details or 404 if not found
     */
    #[Route('/{id}', name: 'agent_get', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
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
            'config'      => $agent->getConnectorConfig()->toArray(),
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
    #[Route('/{id}', name: 'agent_update', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $agent = $this->agentService->findById($id);
        if ($agent === null) {
            return $this->json($this->apiErrorPayloadFactory->create('agent.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $dto = $this->tryParseDto(fn() => UpdateAgentDto::fromArray($request->toArray()));
        if ($dto instanceof JsonResponse) {
            return $dto;
        }

        try {
            $this->agentService->update($agent, $dto);
        } catch (ValidationException $e) {
            return $this->json($this->apiErrorPayloadFactory->fromValidationException($e), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['id' => (string) $agent->getId(), 'name' => $agent->getName()]);
    }

    /**
     * Deletes an agent.
     *
     * @param string $id Agent UUID
     * @return JsonResponse No content on success or 404 if not found
     */
    #[Route('/{id}', name: 'agent_delete', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['DELETE'])]
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
    #[Route('/{id}/status', name: 'agent_status', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
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

    /**
     * Returns the execution history for a given agent.
     *
     * @param string $id Agent UUID
     * @return JsonResponse List of executions with business context (ticket title, task title)
     */
    #[Route('/{id}/executions', name: 'agent_executions', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function executions(string $id): JsonResponse
    {
        $agent = $this->agentService->findById($id);
        if ($agent === null) {
            return $this->json($this->apiErrorPayloadFactory->create('agent.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $executions = $this->agentTaskExecutionRepository->findByAgent($id, 50);

        return $this->json(array_map(
            fn(AgentTaskExecution $execution) => $this->serializeExecution($execution),
            $executions,
        ));
    }

    /**
     * Returns all supported connectors with their model selection metadata.
     */
    #[Route('/connectors', name: 'agent_connectors', methods: ['GET'])]
    public function connectors(): JsonResponse
    {
        return $this->json(array_map(
            static fn ($catalog): array => $catalog->toArray(),
            $this->agentModelCatalogService->listConnectors(),
        ));
    }

    /**
     * Returns the normalized model catalog for one connector.
     */
    #[Route('/connectors/{connector}/models', name: 'agent_connector_models', methods: ['GET'])]
    public function connectorModels(string $connector, Request $request): JsonResponse
    {
        try {
            $connectorType = ConnectorType::from($connector);
        } catch (\ValueError) {
            return $this->json($this->apiErrorPayloadFactory->create('agent.error.connector_not_found'), Response::HTTP_NOT_FOUND);
        }

        return $this->json(
            $this->agentModelCatalogService->describeConnector(
                connector: $connectorType,
                selectedModel: $request->query->get('selectedModel'),
                refresh: $request->query->getBoolean('refresh'),
            )->toArray(),
        );
    }

    /**
     * Serializes an AgentTaskExecution for API response, including business context.
     */
    private function serializeExecution(AgentTaskExecution $execution): array
    {
        $ticketTasks = [];
        foreach ($execution->getTicketTasks() as $task) {
            $ticketTasks[] = [
                'id' => (string) $task->getId(),
                'ticketId' => (string) $task->getTicket()->getId(),
                'ticketTitle' => $task->getTicket()->getTitle(),
                'title' => $task->getTitle(),
            ];
        }

        return [
            'id' => (string) $execution->getId(),
            'traceRef' => $execution->getTraceRef(),
            'triggerType' => $execution->getTriggerType()->value,
            'actionKey' => $execution->getActionKey(),
            'actionLabel' => $execution->getActionLabel(),
            'roleSlug' => $execution->getRoleSlug(),
            'skillSlug' => $execution->getSkillSlug(),
            'status' => $execution->getStatus()->value,
            'currentAttempt' => $execution->getCurrentAttempt(),
            'maxAttempts' => $execution->getMaxAttempts(),
            'requestRef' => $execution->getRequestRef(),
            'lastErrorMessage' => $execution->getLastErrorMessage(),
            'lastErrorScope' => $execution->getLastErrorScope(),
            'createdAt' => $execution->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'startedAt' => $execution->getStartedAt()?->format(\DateTimeInterface::ATOM),
            'finishedAt' => $execution->getFinishedAt()?->format(\DateTimeInterface::ATOM),
            'requestedAgent' => $execution->getRequestedAgent() ? [
                'id' => (string) $execution->getRequestedAgent()->getId(),
                'name' => $execution->getRequestedAgent()->getName(),
            ] : null,
            'effectiveAgent' => $execution->getEffectiveAgent() ? [
                'id' => (string) $execution->getEffectiveAgent()->getId(),
                'name' => $execution->getEffectiveAgent()->getName(),
            ] : null,
            'ticketTasks' => $ticketTasks,
            'attempts' => array_map(fn(AgentTaskExecutionAttempt $attempt) => [
                'id' => (string) $attempt->getId(),
                'attemptNumber' => $attempt->getAttemptNumber(),
                'status' => $attempt->getStatus()->value,
                'willRetry' => $attempt->willRetry(),
                'messengerReceiver' => $attempt->getMessengerReceiver(),
                'requestRef' => $attempt->getRequestRef(),
                'errorMessage' => $attempt->getErrorMessage(),
                'errorScope' => $attempt->getErrorScope(),
                'resourceSnapshot' => $attempt->getResourceSnapshot(),
                'startedAt' => $attempt->getStartedAt()?->format(\DateTimeInterface::ATOM),
                'finishedAt' => $attempt->getFinishedAt()?->format(\DateTimeInterface::ATOM),
                'agent' => $attempt->getAgent() ? [
                    'id' => (string) $attempt->getAgent()->getId(),
                    'name' => $attempt->getAgent()->getName(),
                ] : null,
            ], $execution->getAttempts()->toArray()),
        ];
    }
}
