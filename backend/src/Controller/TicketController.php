<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Controller;

use App\Dto\Input\Ticket\ChangeStatusDto;
use App\Dto\Input\Ticket\CreateCommentDto;
use App\Dto\Input\Ticket\CreateTicketDto;
use App\Dto\Input\Ticket\CreateTicketRequestDto;
use App\Dto\Input\Ticket\CreateTicketTaskDto;
use App\Dto\Input\Ticket\ExecuteTicketTaskDto;
use App\Dto\Input\Ticket\ReprioritizeDto;
use App\Dto\Input\Ticket\UpdateCommentDto;
use App\Dto\Input\Ticket\UpdateProgressDto;
use App\Dto\Input\Ticket\UpdateTicketDto;
use App\Dto\Input\Ticket\UpdateTicketTaskDto;
use App\Entity\AgentTaskExecution;
use App\Entity\AgentTaskExecutionAttempt;
use App\Entity\Ticket;
use App\Entity\TicketLog;
use App\Entity\TicketTask;
use App\Enum\TaskExecutionTrigger;
use App\Enum\TaskPriority;
use App\Enum\TaskStatus;
use App\Enum\TaskType;
use App\Repository\AgentRepository;
use App\Repository\AgentTaskExecutionRepository;
use App\Repository\TicketLogRepository;
use App\Repository\TicketTaskDependencyRepository;
use App\Service\ApiErrorPayloadFactory;
use App\Service\ProjectService;
use App\Service\TicketLogService;
use App\Service\TicketService;
use App\Service\TicketTaskService;
use App\Service\TokenUsageService;
use App\Service\VcsRepositoryUrlService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * REST controller managing tickets, tasks, execution history, and workflow transitions.
 */
#[Route('/api')]
class TicketController extends AbstractController
{
    /**
     * Initializes the controller with its dependencies.
     */
    public function __construct(
        private readonly TicketService $ticketService,
        private readonly TicketTaskService $ticketTaskService,
        private readonly TicketLogService $ticketLogService,
        private readonly ProjectService $projectService,
        private readonly AgentRepository $agentRepository,
        private readonly AgentTaskExecutionRepository $agentTaskExecutionRepository,
        private readonly TicketLogRepository $ticketLogRepository,
        private readonly TicketTaskDependencyRepository $ticketTaskDependencyRepository,
        private readonly TokenUsageService $tokenUsageService,
        private readonly VcsRepositoryUrlService $vcsRepositoryUrl,
        private readonly ApiErrorPayloadFactory $apiErrorPayloadFactory,
    ) {}

    /**
     * Lists all tickets for a given project.
     */
    #[Route('/projects/{projectId}/tickets', name: 'ticket_list_api', methods: ['GET'])]
    public function listTickets(string $projectId): JsonResponse
    {
        $project = $this->projectService->findById($projectId);
        if ($project === null) {
            return $this->json($this->apiErrorPayloadFactory->create('project.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $tickets = $this->ticketService->findByProject($project);

        return $this->json(array_map(
            fn(Ticket $ticket) => $this->serializeApiTicket(
                $ticket,
                includeActiveStepTasks: true,
                includeAllTasks: true,
            ),
            $tickets,
        ));
    }

    /**
     * Creates a new ticket (story or bug) for a project.
     */
    #[Route('/projects/{projectId}/tickets', name: 'ticket_create_api', methods: ['POST'])]
    public function createTicket(string $projectId, Request $request): JsonResponse
    {
        $project = $this->projectService->findById($projectId);
        if ($project === null) {
            return $this->json($this->apiErrorPayloadFactory->create('project.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        try {
            $dto = CreateTicketDto::fromArray($request->toArray());
        } catch (\InvalidArgumentException) {
            return $this->json($this->apiErrorPayloadFactory->create('ticket.validation.title_required'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($dto->type === TaskType::Task) {
            return $this->json($this->apiErrorPayloadFactory->fromMessage('Use POST /api/tickets/{ticketId}/tasks to create an operational task.'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $ticket = $this->ticketService->create(
            $project,
            $dto->type,
            $dto->title,
            $dto->description,
            $dto->priority,
            $dto->featureId,
            initialRequest: $dto->description,
            initialTitle: $dto->title,
        );
        $this->ticketTaskService->dispatchEligibleTasksForCurrentStep($ticket);

        return $this->json($this->serializeApiTicket($ticket), Response::HTTP_CREATED);
    }

    /**
     * Creates a new operational task within a ticket.
     */
    #[Route('/tickets/{ticketId}/tasks', name: 'ticket_task_create_api', methods: ['POST'])]
    public function createTicketTask(string $ticketId, Request $request): JsonResponse
    {
        $ticket = $this->ticketService->findById($ticketId);
        if ($ticket === null) {
            return $this->json($this->apiErrorPayloadFactory->create('ticket.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        try {
            $dto = CreateTicketTaskDto::fromArray($request->toArray());
        } catch (\InvalidArgumentException $e) {
            if ($e->getMessage() === 'action_key_required') {
                return $this->json($this->apiErrorPayloadFactory->fromMessage('An actionKey is required to create a task.'), Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return $this->json($this->apiErrorPayloadFactory->create('ticket.validation.title_required'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $priority = $dto->priority;
        $parent = $dto->parentTaskId !== null ? $this->ticketTaskService->findById($dto->parentTaskId) : null;
        if ($parent !== null && (string) $parent->getTicket()->getId() !== (string) $ticket->getId()) {
            return $this->json($this->apiErrorPayloadFactory->fromMessage('The parent task must belong to the same ticket.'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $task = $this->ticketTaskService->create(
                ticket: $ticket,
                actionKey: $dto->actionKey,
                title: $dto->title,
                description: $dto->description,
                priority: $priority,
                parentId: $parent ? (string) $parent->getId() : null,
                assignedAgentId: $dto->assignedAgentId,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->json($this->apiErrorPayloadFactory->fromMessage($e->getMessage()), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($ticket->getProject()->getTeam() !== null) {
            $this->ticketTaskService->dispatchEligibleTasksForCurrentStep($ticket);
        }

        return $this->json($this->serializeApiTicketTask($task), Response::HTTP_CREATED);
    }

    /**
     * Creates a new request (story) for a project and dispatches eligible tasks.
     */
    #[Route('/projects/{projectId}/requests', name: 'project_request_create', methods: ['POST'])]
    public function createRequest(string $projectId, Request $request): JsonResponse
    {
        $project = $this->projectService->findById($projectId);
        if ($project === null) {
            return $this->json($this->apiErrorPayloadFactory->create('project.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        if (($response = $this->requireProjectTeamForProgress($project)) !== null) {
            return $response;
        }

        try {
            $dto = CreateTicketRequestDto::fromArray($request->toArray());
        } catch (\InvalidArgumentException $e) {
            if ($e->getMessage() === 'description_required') {
                return $this->json($this->apiErrorPayloadFactory->create('ticket.validation.description_required'), Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return $this->json($this->apiErrorPayloadFactory->create('ticket.validation.title_required'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $ticket = $this->ticketService->create(
            $project,
            TaskType::UserStory,
            $dto->title,
            null,
            $dto->priority,
            initialRequest: $dto->description,
            initialTitle: $dto->title,
        );

        $dispatchError = null;
        try {
            $this->ticketTaskService->dispatchEligibleTasksForCurrentStep($ticket);
        } catch (\RuntimeException|\LogicException $e) {
            $dispatchError = $e->getMessage();
        }

        return $this->json(array_merge(
            $this->serializeApiTicket($ticket),
            $dispatchError !== null ? ['dispatchError' => $dispatchError] : [],
        ), Response::HTTP_CREATED);
    }

    /**
     * Retrieves a single ticket with full details.
     */
    #[Route('/tickets/{id}', name: 'ticket_get_api', methods: ['GET'])]
    public function getTicket(string $id): JsonResponse
    {
        $ticket = $this->ticketService->findById($id);
        if ($ticket === null) {
            return $this->json($this->apiErrorPayloadFactory->create('ticket.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->serializeApiTicket(
            $ticket,
            includeActiveStepTasks: true,
            includeAllTasks: true,
            includeLogs: true,
            includeExecutions: true,
            includeTokenUsage: true,
        ));
    }

    /**
     * Retrieves a single ticket task with full details.
     */
    #[Route('/ticket-tasks/{id}', name: 'ticket_task_get_api', methods: ['GET'])]
    public function getTicketTask(string $id): JsonResponse
    {
        $task = $this->ticketTaskService->findById($id);
        if ($task === null) {
            return $this->json($this->apiErrorPayloadFactory->create('ticket.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->serializeApiTicketTask(
            $task,
            includeChildren: true,
            includeLogs: true,
            includeExecutions: true,
            includeTokenUsage: true,
        ));
    }

    /**
     * Updates an existing ticket.
     */
    #[Route('/tickets/{id}', name: 'ticket_update_api', methods: ['PUT'])]
    public function updateTicket(string $id, Request $request): JsonResponse
    {
        $ticket = $this->ticketService->findById($id);
        if ($ticket === null) {
            return $this->json($this->apiErrorPayloadFactory->create('ticket.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $dto = UpdateTicketDto::fromArray($request->toArray());
        $this->ticketService->update(
            $ticket,
            $dto->title ?? $ticket->getTitle(),
            $dto->description,
            $dto->priority ?? $ticket->getPriority(),
            $dto->featureId,
        );

        return $this->json($this->serializeApiTicket($ticket));
    }

    /**
     * Updates an existing ticket task.
     */
    #[Route('/ticket-tasks/{id}', name: 'ticket_task_update_api', methods: ['PUT'])]
    public function updateTicketTask(string $id, Request $request): JsonResponse
    {
        $task = $this->ticketTaskService->findById($id);
        if ($task === null) {
            return $this->json($this->apiErrorPayloadFactory->create('ticket.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $dto = UpdateTicketTaskDto::fromArray($request->toArray());
        try {
            $this->ticketTaskService->update(
                $task,
                $dto->title ?? $task->getTitle(),
                $dto->description,
                $dto->priority ?? $task->getPriority(),
                $dto->actionKey,
                $dto->assignedAgentId,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->json($this->apiErrorPayloadFactory->fromMessage($e->getMessage()), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($this->serializeApiTicketTask($task));
    }

    /**
     * Changes the status of a ticket or ticket task.
     */
    #[Route('/tickets/{id}/status', name: 'ticket_status_api', methods: ['PATCH'])]
    #[Route('/ticket-tasks/{id}/status', name: 'ticket_task_status_api', methods: ['PATCH'])]
    public function changeStatus(string $id, Request $request): JsonResponse
    {
        try {
            $dto = ChangeStatusDto::fromArray($request->toArray());
        } catch (\InvalidArgumentException) {
            return $this->json($this->apiErrorPayloadFactory->create('ticket.validation.status_required'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $status = $dto->status;
        $ticket = $this->ticketService->findById($id);
        if ($ticket !== null) {
            $this->ticketService->changeStatus($ticket, $status);
            return $this->json($this->serializeApiTicket($ticket));
        }

        $task = $this->ticketTaskService->findById($id);
        if ($task === null) {
            return $this->json($this->apiErrorPayloadFactory->create('ticket.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $this->ticketTaskService->changeStatus($task, $status);

        return $this->json($this->serializeApiTicketTask($task));
    }

    /**
     * Updates the progress of a ticket task.
     */
    #[Route('/ticket-tasks/{id}/progress', name: 'ticket_task_progress_api', methods: ['PATCH'])]
    public function updateProgress(string $id, Request $request): JsonResponse
    {
        $task = $this->ticketTaskService->findById($id);
        if ($task === null) {
            return $this->json($this->apiErrorPayloadFactory->create('ticket.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $dto = UpdateProgressDto::fromArray($request->toArray());
        $this->ticketTaskService->updateProgress($task, $dto->progress);

        return $this->json($this->serializeApiTicketTask($task));
    }

    /**
     * Reprioritizes a ticket or ticket task.
     */
    #[Route('/tickets/{id}/priority', name: 'ticket_reprioritize_api', methods: ['PATCH'])]
    #[Route('/ticket-tasks/{id}/priority', name: 'ticket_task_reprioritize_api', methods: ['PATCH'])]
    public function reprioritize(string $id, Request $request): JsonResponse
    {
        try {
            $dto = ReprioritizeDto::fromArray($request->toArray());
        } catch (\InvalidArgumentException) {
            return $this->json($this->apiErrorPayloadFactory->create('ticket.validation.priority_required'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $priority = $dto->priority;
        $ticket = $this->ticketService->findById($id);
        if ($ticket !== null) {
            $this->ticketService->update($ticket, $ticket->getTitle(), $ticket->getDescription(), $priority, $ticket->getFeature()?->getId()->toRfc4122());
            return $this->json($this->serializeApiTicket($ticket));
        }

        $task = $this->ticketTaskService->findById($id);
        if ($task === null) {
            return $this->json($this->apiErrorPayloadFactory->create('ticket.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $this->ticketTaskService->reprioritize($task, $priority);

        return $this->json($this->serializeApiTicketTask($task));
    }

    /**
     * Adds a comment to a ticket or ticket task.
     */
    #[Route('/tickets/{id}/comments', name: 'ticket_comment_create_api', methods: ['POST'])]
    #[Route('/ticket-tasks/{id}/comments', name: 'ticket_task_comment_create_api', methods: ['POST'])]
    public function createComment(string $id, Request $request): JsonResponse
    {
        try {
            $dto = CreateCommentDto::fromArray($request->toArray());
        } catch (\InvalidArgumentException) {
            return $this->json($this->apiErrorPayloadFactory->create('ticket.validation.content_required'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $ticket = $this->ticketService->findById($id);
        $ticketTask = null;
        if ($ticket === null) {
            $ticketTask = $this->ticketTaskService->findById($id);
            $ticket = $ticketTask?->getTicket();
        }

        if ($ticket === null) {
            return $this->json($this->apiErrorPayloadFactory->create('ticket.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        try {
            $log = $this->ticketLogService->addComment(
                ticket: $ticket,
                content: $dto->content,
                ticketTask: $ticketTask,
                authorType: 'user',
                authorName: 'Vous',
                replyToId: $dto->replyToLogId,
                requiresAnswer: false,
                metadata: [
                    'context' => $dto->context ?? 'ticket_comment',
                ],
                action: $dto->replyToLogId !== null ? 'user_reply' : 'user_comment',
            );
        } catch (\InvalidArgumentException $e) {
            return $this->json($this->apiErrorPayloadFactory->fromMessage($e->getMessage()), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($this->serializeApiLog($log), Response::HTTP_CREATED);
    }

    /**
     * Edits one existing user-authored comment or reply on a ticket or ticket task.
     */
    #[Route('/tickets/{id}/comments/{logId}', name: 'ticket_comment_update_api', methods: ['PATCH'])]
    #[Route('/ticket-tasks/{id}/comments/{logId}', name: 'ticket_task_comment_update_api', methods: ['PATCH'])]
    public function updateComment(string $id, string $logId, Request $request): JsonResponse
    {
        try {
            $dto = UpdateCommentDto::fromArray($request->toArray());
        } catch (\InvalidArgumentException) {
            return $this->json($this->apiErrorPayloadFactory->create('ticket.validation.content_required'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $ticket = $this->ticketService->findById($id);
        $ticketTask = null;
        if ($ticket === null) {
            $ticketTask = $this->ticketTaskService->findById($id);
            $ticket = $ticketTask?->getTicket();
        }

        if ($ticket === null) {
            return $this->json($this->apiErrorPayloadFactory->create('ticket.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        try {
            $log = $this->ticketLogService->editComment($ticket, $logId, $dto->content, $ticketTask);
        } catch (\InvalidArgumentException $e) {
            $key = $e->getMessage();
            if ($key === 'ticket.comment.error.not_found') {
                return $this->json($this->apiErrorPayloadFactory->create('ticket.comment.error.not_found'), Response::HTTP_NOT_FOUND);
            }

            return $this->json($this->apiErrorPayloadFactory->create('ticket.comment.error.not_editable'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($this->serializeApiLog($log));
    }

    /**
     * Deletes one existing user-authored comment or reply on a ticket or ticket task.
     */
    #[Route('/tickets/{id}/comments/{logId}', name: 'ticket_comment_delete_api', methods: ['DELETE'])]
    #[Route('/ticket-tasks/{id}/comments/{logId}', name: 'ticket_task_comment_delete_api', methods: ['DELETE'])]
    public function deleteComment(string $id, string $logId): JsonResponse
    {
        $ticket = $this->ticketService->findById($id);
        $ticketTask = null;
        if ($ticket === null) {
            $ticketTask = $this->ticketTaskService->findById($id);
            $ticket = $ticketTask?->getTicket();
        }

        if ($ticket === null) {
            return $this->json($this->apiErrorPayloadFactory->create('ticket.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        try {
            $this->ticketLogService->deleteComment($ticket, $logId, $ticketTask);
        } catch (\InvalidArgumentException $e) {
            $key = $e->getMessage();
            if ($key === 'ticket.comment.error.not_found') {
                return $this->json($this->apiErrorPayloadFactory->create('ticket.comment.error.not_found'), Response::HTTP_NOT_FOUND);
            }

            if ($key === 'ticket.comment.error.has_replies') {
                return $this->json($this->apiErrorPayloadFactory->create('ticket.comment.error.has_replies'), Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return $this->json($this->apiErrorPayloadFactory->create('ticket.comment.error.not_deletable'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Advances a ticket to the next workflow step.
     */
    #[Route('/tickets/{id}/advance', name: 'ticket_advance_api', methods: ['POST'])]
    public function advance(string $id): JsonResponse
    {
        $ticket = $this->ticketService->findById($id);
        if ($ticket === null) {
            return $this->json($this->apiErrorPayloadFactory->create('ticket.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        if (($response = $this->requireProjectTeamForProgress($ticket->getProject())) !== null) {
            return $response;
        }

        if (!$ticket->isStory()) {
            return $this->json($this->apiErrorPayloadFactory->create('ticket.story.error.unsupported_user_story_or_bug'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->ticketService->advanceWorkflowStep($ticket);
            $this->ticketTaskService->dispatchEligibleTasksForCurrentStep($ticket);
        } catch (\LogicException $e) {
            return $this->json($this->apiErrorPayloadFactory->fromMessage($e->getMessage()), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($this->serializeApiTicket($ticket));
    }

    /**
     * Resumes an agent step from a ticket task.
     *
     * TODO: Replace raw request parsing with a dedicated input DTO for this write endpoint.
     */
    #[Route('/ticket-tasks/{id}/resume', name: 'ticket_task_resume_api', methods: ['POST'])]
    public function resume(string $id, Request $request): JsonResponse
    {
        $task = $this->ticketTaskService->findById($id);
        if ($task === null) {
            return $this->json($this->apiErrorPayloadFactory->create('ticket.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        if (($response = $this->requireProjectTeamForProgress($task->getTicket()->getProject())) !== null) {
            return $response;
        }

        try {
            $result = $this->ticketTaskService->resume($task, $task->getAssignedAgent());
        } catch (\RuntimeException|\LogicException $e) {
            return $this->json($this->apiErrorPayloadFactory->fromMessage($e->getMessage()), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'ticketTask' => $this->serializeApiTicketTask($task),
            'agent' => ['id' => (string) $result['agent']->getId(), 'name' => $result['agent']->getName()],
            'skill' => $result['skill'],
            'executionId' => $result['executionId'],
        ]);
    }

    /**
     * Returns available agents for executing a ticket task.
     */
    #[Route('/tickets/{id}/execute', name: 'ticket_execute_agents_api', methods: ['GET'])]
    #[Route('/ticket-tasks/{id}/execute', name: 'ticket_task_execute_agents_api', methods: ['GET'])]
    public function executeAgents(string $id): JsonResponse
    {
        $ticket = $this->ticketService->findById($id);
        if ($ticket !== null) {
            return $this->json($this->apiErrorPayloadFactory->create('ticket.execution.error.ticket_level_execution_unsupported'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $task = $this->ticketTaskService->findById($id);
        if ($task === null) {
            return $this->json($this->apiErrorPayloadFactory->create('ticket.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        if (($response = $this->requireProjectTeamForProgress($task->getTicket()->getProject())) !== null) {
            return $response;
        }

        $agents = $this->ticketTaskService->availableAgents($task);

        return $this->json(array_map(fn($a) => [
            'id' => (string) $a->getId(),
            'name' => $a->getName(),
            'role' => $a->getRole() ? ['slug' => $a->getRole()->getSlug(), 'name' => $a->getRole()->getName()] : null,
        ], $agents));
    }

    /**
     * Executes a ticket task with the specified agent.
     */
    #[Route('/tickets/{id}/execute', name: 'ticket_execute_api', methods: ['POST'])]
    #[Route('/ticket-tasks/{id}/execute', name: 'ticket_task_execute_api', methods: ['POST'])]
    public function execute(string $id, Request $request): JsonResponse
    {
        $ticket = $this->ticketService->findById($id);
        $dto = ExecuteTicketTaskDto::fromArray($request->toArray());
        $agent = null;
        if ($dto->agentId !== null) {
            $agent = $this->agentRepository->find($dto->agentId);
            if ($agent === null) {
                return $this->json($this->apiErrorPayloadFactory->create('ticket.execution.error.agent_not_found'), Response::HTTP_NOT_FOUND);
            }
        }

        if ($ticket !== null) {
            return $this->json($this->apiErrorPayloadFactory->create('ticket.execution.error.ticket_level_execution_unsupported'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $task = $this->ticketTaskService->findById($id);
        if ($task === null) {
            return $this->json($this->apiErrorPayloadFactory->create('ticket.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        if (($response = $this->requireProjectTeamForProgress($task->getTicket()->getProject())) !== null) {
            return $response;
        }

        try {
            $result = $this->ticketTaskService->execute($task, $agent, TaskExecutionTrigger::Manual);
        } catch (\RuntimeException|\LogicException $e) {
            return $this->json($this->apiErrorPayloadFactory->fromMessage($e->getMessage()), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'ticketTask' => $this->serializeApiTicketTask($task),
            'agent' => ['id' => (string) $result['agent']->getId(), 'name' => $result['agent']->getName()],
            'skill' => $result['skill'],
            'executionId' => $result['executionId'],
        ]);
    }

    /**
     * Deletes a ticket and its associated tasks.
     */
    #[Route('/tickets/{id}', name: 'ticket_delete_api', methods: ['DELETE'])]
    public function deleteTicket(string $id): JsonResponse
    {
        $ticket = $this->ticketService->findById($id);
        if ($ticket === null) {
            return $this->json($this->apiErrorPayloadFactory->create('ticket.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $this->ticketService->delete($ticket);

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Deletes a ticket task.
     */
    #[Route('/ticket-tasks/{id}', name: 'ticket_task_delete_api', methods: ['DELETE'])]
    public function deleteTicketTask(string $id): JsonResponse
    {
        $task = $this->ticketTaskService->findById($id);
        if ($task === null) {
            return $this->json($this->apiErrorPayloadFactory->create('ticket.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $this->ticketTaskService->delete($task);

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    /** @return AgentTaskExecution[] */
    private function collectTicketExecutions(Ticket $ticket): array
    {
        $seen = [];
        foreach ($ticket->getTasks() as $task) {
            foreach ($this->agentTaskExecutionRepository->findByTicketTask($task) as $execution) {
                $seen[(string) $execution->getId()] = $execution;
            }
        }

        return array_values($seen);
    }

    private function serializeExecution(AgentTaskExecution $execution): array
    {
        return [
            'id' => (string) $execution->getId(),
            'traceRef' => $execution->getTraceRef(),
            'triggerType' => $execution->getTriggerType()->value,
            'workflowStepKey' => null,
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
            'ticketTaskIds' => array_map(
                static fn(TicketTask $task) => (string) $task->getId(),
                $execution->getTicketTasks()->toArray(),
            ),
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

    private function serializeApiTicket(
        Ticket $ticket,
        bool $includeActiveStepTasks = false,
        bool $includeAllTasks = false,
        bool $includeLogs = false,
        bool $includeExecutions = false,
        bool $includeTokenUsage = false,
    ): array {
        $tasks = $this->ticketTaskService->findByTicket($ticket);
        $rootTasks = array_values(array_filter($tasks, static fn(TicketTask $task) => $task->getParent() === null));
        $activeStepTasks = array_values(array_filter(
            $rootTasks,
            fn(TicketTask $task) => $task->getWorkflowStep()?->getId()?->toRfc4122() === $ticket->getWorkflowStep()?->getId()?->toRfc4122()
        ));
        $pendingAnswerSummary = $this->buildPendingAnswerSummary($ticket);
        $workflowProgress = $this->ticketTaskService->describeWorkflowStepProgress($ticket);

        $payload = [
            'id' => (string) $ticket->getId(),
            'projectId' => (string) $ticket->getProject()->getId(),
            'feature' => $ticket->getFeature() ? ['id' => (string) $ticket->getFeature()->getId(), 'name' => $ticket->getFeature()->getName()] : null,
            'type' => $ticket->getType()->value,
            'title' => $ticket->getTitle(),
            'description' => $ticket->getDescription(),
            'initialRequest' => $ticket->getInitialRequest(),
            'initialTitle' => $ticket->getInitialTitle(),
            'status' => $ticket->getStatus()->value,
            'priority' => $ticket->getPriority()->value,
            'progress' => $ticket->getProgress(),
            'branchName' => $ticket->getBranchName(),
            'branchUrl' => $this->vcsRepositoryUrl->buildBranchUrl($ticket->getProject()->getRepositoryUrl(), $ticket->getBranchName()),
            'workflowStep' => $ticket->getWorkflowStep() ? [
                'id' => (string) $ticket->getWorkflowStep()->getId(),
                'key' => $ticket->getWorkflowStep()->getKey(),
                'name' => $ticket->getWorkflowStep()->getName(),
            ] : null,
            'workflowStepAllowedTransitions' => array_map(
                fn($step) => [
                    'id' => (string) $step->getId(),
                    'key' => $step->getKey(),
                    'name' => $step->getName(),
                ],
                $this->ticketService->findAllowedManualTransitions($ticket),
            ),
            'assignedAgent' => $ticket->getAssignedAgent() ? ['id' => (string) $ticket->getAssignedAgent()->getId(), 'name' => $ticket->getAssignedAgent()->getName()] : null,
            'assignedRole' => $ticket->getAssignedRole() ? ['id' => (string) $ticket->getAssignedRole()->getId(), 'slug' => $ticket->getAssignedRole()->getSlug(), 'name' => $ticket->getAssignedRole()->getName()] : null,
            'awaitingUserAnswer' => $pendingAnswerSummary['ticket'] > 0,
            'pendingUserAnswerCount' => $pendingAnswerSummary['ticket'],
            'workflowProgress' => $workflowProgress,
            'createdAt' => $ticket->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $ticket->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'taskCounts' => [
                'total' => count($tasks),
                'root' => count($rootTasks),
                'activeStep' => count($activeStepTasks),
            ],
        ];

        if ($includeActiveStepTasks) {
            $payload['activeStepTasks'] = array_map(
                fn(TicketTask $task) => $this->serializeApiTicketTask(
                    $task,
                    pendingAnswerCount: $pendingAnswerSummary['tasks'][(string) $task->getId()] ?? 0,
                ),
                $activeStepTasks,
            );
        }

        if ($includeAllTasks) {
            $payload['tasks'] = array_map(
                fn(TicketTask $task) => $this->serializeApiTicketTask(
                    $task,
                    pendingAnswerCount: $pendingAnswerSummary['tasks'][(string) $task->getId()] ?? 0,
                ),
                $tasks,
            );
        }

        if ($includeLogs) {
            $payload['logs'] = array_map(
                fn(TicketLog $log) => $this->serializeApiLog($log),
                $this->ticketLogRepository->findByTicket($ticket),
            );
        }

        if ($includeExecutions) {
            $payload['executions'] = array_map(
                fn(AgentTaskExecution $execution) => $this->serializeExecution($execution),
                $this->collectTicketExecutions($ticket),
            );
        }

        if ($includeTokenUsage) {
            $payload['tokenUsage'] = $this->tokenUsageService->findByTicket($ticket);
        }

        return $payload;
    }

    private function serializeApiTicketTask(
        TicketTask $task,
        bool $includeChildren = false,
        bool $includeLogs = false,
        bool $includeExecutions = false,
        bool $includeTokenUsage = false,
        ?int $pendingAnswerCount = null,
    ): array {
        $dependencies = $this->ticketTaskDependencyRepository->findByTicketTask($task);
        $children = $includeChildren ? $this->ticketTaskService->findChildren($task) : [];
        $pendingAnswerCount ??= $this->countPendingAnswersForTask($task);

        $payload = [
            'id' => (string) $task->getId(),
            'ticketId' => (string) $task->getTicket()->getId(),
            'parentTaskId' => $task->getParent()?->getId()->toRfc4122(),
            'title' => $task->getTitle(),
            'description' => $task->getDescription(),
            'status' => $task->getStatus()->value,
            'priority' => $task->getPriority()->value,
            'progress' => $task->getProgress(),
            'branchName' => $task->getBranchName(),
            'branchUrl' => $this->vcsRepositoryUrl->buildBranchUrl($task->getTicket()->getProject()->getRepositoryUrl(), $task->getBranchName()),
            'workflowStep' => $task->getWorkflowStep() ? [
                'id' => (string) $task->getWorkflowStep()->getId(),
                'key' => $task->getWorkflowStep()->getKey(),
                'name' => $task->getWorkflowStep()->getName(),
            ] : null,
            'agentAction' => [
                'id' => (string) $task->getAgentAction()->getId(),
                'key' => $task->getAgentAction()->getKey(),
                'label' => $task->getAgentAction()->getLabel(),
                'allowedEffects' => $task->getAgentAction()->getAllowedEffects(),
                'role' => $task->getAgentAction()->getRole() ? [
                    'id' => (string) $task->getAgentAction()->getRole()->getId(),
                    'slug' => $task->getAgentAction()->getRole()->getSlug(),
                    'name' => $task->getAgentAction()->getRole()->getName(),
                ] : null,
                'skill' => $task->getAgentAction()->getSkill() ? [
                    'id' => (string) $task->getAgentAction()->getSkill()->getId(),
                    'slug' => $task->getAgentAction()->getSkill()->getSlug(),
                    'name' => $task->getAgentAction()->getSkill()->getName(),
                ] : null,
            ],
            'assignedAgent' => $task->getAssignedAgent() ? ['id' => (string) $task->getAssignedAgent()->getId(), 'name' => $task->getAssignedAgent()->getName()] : null,
            'assignedRole' => $task->getAssignedRole() ? ['id' => (string) $task->getAssignedRole()->getId(), 'slug' => $task->getAssignedRole()->getSlug(), 'name' => $task->getAssignedRole()->getName()] : null,
            'awaitingUserAnswer' => $pendingAnswerCount > 0,
            'pendingUserAnswerCount' => $pendingAnswerCount,
            'canResume' => $this->ticketTaskService->canResume($task),
            'canManualDispatch' => $task->getStatus() === TaskStatus::AwaitingDispatch,
            'dependsOn' => array_map(
                static fn($dependency) => [
                    'id' => (string) $dependency->getDependsOn()->getId(),
                    'title' => $dependency->getDependsOn()->getTitle(),
                    'status' => $dependency->getDependsOn()->getStatus()->value,
                ],
                $dependencies,
            ),
            'childTaskIds' => array_map(
                static fn(TicketTask $child) => (string) $child->getId(),
                $this->ticketTaskService->findChildren($task),
            ),
            'createdAt' => $task->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $task->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];

        if ($includeChildren) {
            $payload['children'] = array_map(
                fn(TicketTask $child) => $this->serializeApiTicketTask($child),
                $children,
            );
        }

        if ($includeLogs) {
            $payload['logs'] = array_map(
                fn(TicketLog $log) => $this->serializeApiLog($log),
                $this->ticketLogRepository->findByTicketTask($task),
            );
        }

        if ($includeExecutions) {
            $payload['executions'] = array_map(
                fn(AgentTaskExecution $execution) => $this->serializeExecution($execution),
                $this->agentTaskExecutionRepository->findByTicketTask($task),
            );
        }

        if ($includeTokenUsage) {
            $payload['tokenUsage'] = $this->tokenUsageService->findByTicketTask($task);
        }

        return $payload;
    }

    /**
     * Builds unresolved answer counts for a ticket and its linked tasks from ticket logs.
     *
     * @return array{ticket: int, tasks: array<string, int>}
     */
    private function buildPendingAnswerSummary(Ticket $ticket): array
    {
        $taskCounts = [];
        $ticketCount = 0;

        foreach ($this->ticketLogRepository->findByTicket($ticket) as $log) {
            if (!$log->requiresAnswer()) {
                continue;
            }

            $ticketCount++;

            $taskId = $log->getTicketTask()?->getId()->toRfc4122();
            if ($taskId === null) {
                continue;
            }

            $taskCounts[$taskId] = ($taskCounts[$taskId] ?? 0) + 1;
        }

        return [
            'ticket' => $ticketCount,
            'tasks' => $taskCounts,
        ];
    }

    /**
     * Counts unresolved answer requests directly linked to one operational task.
     */
    private function countPendingAnswersForTask(TicketTask $task): int
    {
        return $this->ticketLogService->countPendingAnswersForTask($task);
    }

    private function serializeApiLog(TicketLog $log): array
    {
        return [
            'id' => (string) $log->getId(),
            'ticketId' => (string) $log->getTicket()->getId(),
            'ticketTaskId' => $log->getTicketTask()?->getId()->toRfc4122(),
            'action' => $log->getAction(),
            'kind' => $log->getKind(),
            'authorType' => $log->getAuthorType(),
            'authorName' => $log->getAuthorName(),
            'requiresAnswer' => $log->requiresAnswer(),
            'replyToLogId' => $log->getReplyToLogId()?->toRfc4122(),
            'metadata' => $log->getMetadata(),
            'content' => $log->getContent(),
            'createdAt' => $log->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    private function requireProjectTeamForProgress(\App\Entity\Project $project): ?JsonResponse
    {
        if ($project->getTeam() !== null) {
            return null;
        }

        return $this->json(
            $this->apiErrorPayloadFactory->create('project.progress.error.team_required'),
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
