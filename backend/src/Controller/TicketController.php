<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AgentTaskExecution;
use App\Entity\AgentTaskExecutionAttempt;
use App\Entity\Ticket;
use App\Entity\TicketLog;
use App\Entity\TicketTask;
use App\Enum\StoryStatus;
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
use App\Service\StoryExecutionService;
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

#[Route('/api')]
class TicketController extends AbstractController
{
    public function __construct(
        private readonly TicketService $ticketService,
        private readonly TicketTaskService $ticketTaskService,
        private readonly TicketLogService $ticketLogService,
        private readonly ProjectService $projectService,
        private readonly StoryExecutionService $storyExecutionService,
        private readonly AgentRepository $agentRepository,
        private readonly AgentTaskExecutionRepository $agentTaskExecutionRepository,
        private readonly TicketLogRepository $ticketLogRepository,
        private readonly TicketTaskDependencyRepository $ticketTaskDependencyRepository,
        private readonly TokenUsageService $tokenUsageService,
        private readonly VcsRepositoryUrlService $vcsRepositoryUrl,
        private readonly ApiErrorPayloadFactory $apiErrorPayloadFactory,
    ) {}

    #[Route('/projects/{projectId}/tickets', name: 'ticket_list_api', methods: ['GET'])]
    public function listTickets(string $projectId): JsonResponse
    {
        $project = $this->projectService->findById($projectId);
        if ($project === null) {
            return $this->json($this->apiErrorPayloadFactory->create('projects.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $tickets = $this->ticketService->findByProject($project);

        return $this->json(array_map(
            fn(Ticket $ticket) => $this->serializeApiTicket($ticket, includeActiveStepTasks: true),
            $tickets,
        ));
    }

    #[Route('/projects/{projectId}/tickets', name: 'ticket_create_api', methods: ['POST'])]
    public function createTicket(string $projectId, Request $request): JsonResponse
    {
        $project = $this->projectService->findById($projectId);
        if ($project === null) {
            return $this->json($this->apiErrorPayloadFactory->create('projects.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $data = $request->toArray();
        if (empty($data['title'])) {
            return $this->json($this->apiErrorPayloadFactory->create('tickets.validation.title_required'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $type = TaskType::from($data['type'] ?? TaskType::UserStory->value);
        if ($type === TaskType::Task) {
            return $this->json($this->apiErrorPayloadFactory->fromMessage('Utiliser POST /api/tickets/{ticketId}/tasks pour créer une tâche opérationnelle.'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $priority = TaskPriority::from($data['priority'] ?? TaskPriority::Medium->value);
        $ticket = $this->ticketService->create(
            $project,
            $type,
            (string) $data['title'],
            $data['description'] ?? null,
            $priority,
            $data['featureId'] ?? null,
        );

        return $this->json($this->serializeApiTicket($ticket), Response::HTTP_CREATED);
    }

    #[Route('/tickets/{ticketId}/tasks', name: 'ticket_task_create_api', methods: ['POST'])]
    public function createTicketTask(string $ticketId, Request $request): JsonResponse
    {
        $ticket = $this->ticketService->findById($ticketId);
        if ($ticket === null) {
            return $this->json($this->apiErrorPayloadFactory->create('tickets.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $data = $request->toArray();
        if (empty($data['title'])) {
            return $this->json($this->apiErrorPayloadFactory->create('tickets.validation.title_required'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $priority = TaskPriority::from($data['priority'] ?? TaskPriority::Medium->value);
        $parent = isset($data['parentTaskId']) ? $this->ticketTaskService->findById((string) $data['parentTaskId']) : null;
        if ($parent !== null && (string) $parent->getTicket()->getId() !== (string) $ticket->getId()) {
            return $this->json($this->apiErrorPayloadFactory->fromMessage('La tâche parente doit appartenir au même ticket.'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $task = $this->ticketTaskService->create(
            ticket: $ticket,
            actionKey: (string) ($data['actionKey'] ?? 'dev.backend.implement'),
            title: (string) $data['title'],
            description: $data['description'] ?? null,
            priority: $priority,
            parentId: $parent ? (string) $parent->getId() : null,
            assignedAgentId: $data['assignedAgentId'] ?? null,
        );

        return $this->json($this->serializeApiTicketTask($task), Response::HTTP_CREATED);
    }

    #[Route('/projects/{projectId}/requests', name: 'project_request_create', methods: ['POST'])]
    public function createRequest(string $projectId, Request $request): JsonResponse
    {
        $project = $this->projectService->findById($projectId);
        if ($project === null) {
            return $this->json($this->apiErrorPayloadFactory->create('projects.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        if (($response = $this->requireProjectTeamForProgress($project)) !== null) {
            return $response;
        }

        $data = $request->toArray();
        if (empty($data['title'])) {
            return $this->json($this->apiErrorPayloadFactory->create('tickets.validation.title_required'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $priority = TaskPriority::from($data['priority'] ?? TaskPriority::Medium->value);
        $ticket = $this->ticketService->create(
            $project,
            TaskType::UserStory,
            (string) $data['title'],
            $data['description'] ?? null,
            $priority,
        );

        $dispatchError = null;
        try {
            $this->storyExecutionService->execute($ticket, null, TaskExecutionTrigger::Auto);
        } catch (\RuntimeException|\LogicException $e) {
            $dispatchError = $e->getMessage();
        }

        return $this->json(array_merge(
            $this->serializeApiTicket($ticket),
            $dispatchError !== null ? ['dispatchError' => $dispatchError] : [],
        ), Response::HTTP_CREATED);
    }

    #[Route('/tickets/{id}', name: 'ticket_get_api', methods: ['GET'])]
    public function getTicket(string $id): JsonResponse
    {
        $ticket = $this->ticketService->findById($id);
        if ($ticket === null) {
            return $this->json($this->apiErrorPayloadFactory->create('tickets.error.not_found'), Response::HTTP_NOT_FOUND);
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

    #[Route('/ticket-tasks/{id}', name: 'ticket_task_get_api', methods: ['GET'])]
    public function getTicketTask(string $id): JsonResponse
    {
        $task = $this->ticketTaskService->findById($id);
        if ($task === null) {
            return $this->json($this->apiErrorPayloadFactory->create('tickets.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->serializeApiTicketTask(
            $task,
            includeChildren: true,
            includeLogs: true,
            includeExecutions: true,
            includeTokenUsage: true,
        ));
    }

    #[Route('/tickets/{id}', name: 'ticket_update_api', methods: ['PUT'])]
    public function updateTicket(string $id, Request $request): JsonResponse
    {
        $ticket = $this->ticketService->findById($id);
        if ($ticket === null) {
            return $this->json($this->apiErrorPayloadFactory->create('tickets.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $data = $request->toArray();
        $priority = isset($data['priority']) ? TaskPriority::from($data['priority']) : $ticket->getPriority();
        $this->ticketService->update(
            $ticket,
            $data['title'] ?? $ticket->getTitle(),
            $data['description'] ?? null,
            $priority,
            $data['featureId'] ?? null,
        );

        return $this->json($this->serializeApiTicket($ticket));
    }

    #[Route('/ticket-tasks/{id}', name: 'ticket_task_update_api', methods: ['PUT'])]
    public function updateTicketTask(string $id, Request $request): JsonResponse
    {
        $task = $this->ticketTaskService->findById($id);
        if ($task === null) {
            return $this->json($this->apiErrorPayloadFactory->create('tickets.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $data = $request->toArray();
        $priority = isset($data['priority']) ? TaskPriority::from($data['priority']) : $task->getPriority();
        $this->ticketTaskService->update(
            $task,
            $data['title'] ?? $task->getTitle(),
            $data['description'] ?? null,
            $priority,
            $data['actionKey'] ?? null,
            $data['assignedAgentId'] ?? null,
        );

        return $this->json($this->serializeApiTicketTask($task));
    }

    #[Route('/tickets/{id}/status', name: 'ticket_status_api', methods: ['PATCH'])]
    #[Route('/ticket-tasks/{id}/status', name: 'ticket_task_status_api', methods: ['PATCH'])]
    public function changeStatus(string $id, Request $request): JsonResponse
    {
        $data = $request->toArray();
        if (empty($data['status'])) {
            return $this->json($this->apiErrorPayloadFactory->create('tickets.validation.status_required'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $status = TaskStatus::from($data['status']);
        $ticket = $this->ticketService->findById($id);
        if ($ticket !== null) {
            $this->ticketService->changeStatus($ticket, $status);
            return $this->json($this->serializeApiTicket($ticket));
        }

        $task = $this->ticketTaskService->findById($id);
        if ($task === null) {
            return $this->json($this->apiErrorPayloadFactory->create('tickets.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $this->ticketTaskService->changeStatus($task, $status);

        return $this->json($this->serializeApiTicketTask($task));
    }

    #[Route('/ticket-tasks/{id}/progress', name: 'ticket_task_progress_api', methods: ['PATCH'])]
    public function updateProgress(string $id, Request $request): JsonResponse
    {
        $task = $this->ticketTaskService->findById($id);
        if ($task === null) {
            return $this->json($this->apiErrorPayloadFactory->create('tickets.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $data = $request->toArray();
        $this->ticketTaskService->updateProgress($task, (int) ($data['progress'] ?? 0));

        return $this->json($this->serializeApiTicketTask($task));
    }

    #[Route('/tickets/{id}/priority', name: 'ticket_reprioritize_api', methods: ['PATCH'])]
    #[Route('/ticket-tasks/{id}/priority', name: 'ticket_task_reprioritize_api', methods: ['PATCH'])]
    public function reprioritize(string $id, Request $request): JsonResponse
    {
        $data = $request->toArray();
        if (empty($data['priority'])) {
            return $this->json($this->apiErrorPayloadFactory->create('tickets.validation.priority_required'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $priority = TaskPriority::from($data['priority']);
        $ticket = $this->ticketService->findById($id);
        if ($ticket !== null) {
            $this->ticketService->update($ticket, $ticket->getTitle(), $ticket->getDescription(), $priority, $ticket->getFeature()?->getId()->toRfc4122());
            return $this->json($this->serializeApiTicket($ticket));
        }

        $task = $this->ticketTaskService->findById($id);
        if ($task === null) {
            return $this->json($this->apiErrorPayloadFactory->create('tickets.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $this->ticketTaskService->reprioritize($task, $priority);

        return $this->json($this->serializeApiTicketTask($task));
    }

    #[Route('/ticket-tasks/{id}/validate', name: 'ticket_task_validate_api', methods: ['POST'])]
    public function validate(string $id, Request $request): JsonResponse
    {
        $task = $this->ticketTaskService->findById($id);
        if ($task === null) {
            return $this->json($this->apiErrorPayloadFactory->create('tickets.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $this->ticketTaskService->validate($task);

        return $this->json($this->serializeApiTicketTask($task));
    }

    #[Route('/ticket-tasks/{id}/reject', name: 'ticket_task_reject_api', methods: ['POST'])]
    public function reject(string $id, Request $request): JsonResponse
    {
        $task = $this->ticketTaskService->findById($id);
        if ($task === null) {
            return $this->json($this->apiErrorPayloadFactory->create('tickets.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $data = $request->toArray();
        $this->ticketTaskService->reject($task, $data['reason'] ?? null);

        return $this->json($this->serializeApiTicketTask($task));
    }

    #[Route('/ticket-tasks/{id}/request-validation', name: 'ticket_task_request_validation_api', methods: ['POST'])]
    public function requestValidation(string $id, Request $request): JsonResponse
    {
        $task = $this->ticketTaskService->findById($id);
        if ($task === null) {
            return $this->json($this->apiErrorPayloadFactory->create('tickets.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $data = $request->toArray();
        $this->ticketTaskService->requestValidation($task, $data['comment'] ?? null);

        return $this->json($this->serializeApiTicketTask($task));
    }

    #[Route('/tickets/{id}/comments', name: 'ticket_comment_create_api', methods: ['POST'])]
    #[Route('/ticket-tasks/{id}/comments', name: 'ticket_task_comment_create_api', methods: ['POST'])]
    public function createComment(string $id, Request $request): JsonResponse
    {
        $data = $request->toArray();
        $content = trim((string) ($data['content'] ?? ''));
        if ($content === '') {
            return $this->json($this->apiErrorPayloadFactory->create('tickets.validation.content_required'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $ticket = $this->ticketService->findById($id);
        $ticketTask = null;
        if ($ticket === null) {
            $ticketTask = $this->ticketTaskService->findById($id);
            $ticket = $ticketTask?->getTicket();
        }

        if ($ticket === null) {
            return $this->json($this->apiErrorPayloadFactory->create('tickets.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        try {
            $log = $this->ticketLogService->addComment(
                ticket: $ticket,
                content: $content,
                ticketTask: $ticketTask,
                authorType: 'user',
                authorName: 'Vous',
                replyToId: $data['replyToLogId'] ?? null,
                requiresAnswer: false,
                metadata: [
                    'context' => $data['context'] ?? 'ticket_comment',
                ],
                action: isset($data['replyToLogId']) ? 'user_reply' : 'user_comment',
            );
        } catch (\InvalidArgumentException $e) {
            return $this->json($this->apiErrorPayloadFactory->fromMessage($e->getMessage()), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($this->serializeApiLog($log), Response::HTTP_CREATED);
    }

    #[Route('/tickets/{id}/story-transition', name: 'ticket_story_transition_api', methods: ['POST'])]
    public function storyTransition(string $id, Request $request): JsonResponse
    {
        $ticket = $this->ticketService->findById($id);
        if ($ticket === null) {
            return $this->json($this->apiErrorPayloadFactory->create('tickets.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        if (($response = $this->requireProjectTeamForProgress($ticket->getProject())) !== null) {
            return $response;
        }

        if (!$ticket->isStory()) {
            return $this->json($this->apiErrorPayloadFactory->create('tickets.story.error.unsupported_user_story_or_bug'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $data = $request->toArray();
        if (empty($data['status'])) {
            return $this->json($this->apiErrorPayloadFactory->create('tickets.validation.status_required'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $next = StoryStatus::tryFrom($data['status']);
        if ($next === null) {
            return $this->json($this->apiErrorPayloadFactory->create('tickets.story.error.unknown_status', ['%status%' => (string) $data['status']]), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->ticketService->transitionStory($ticket, $next);
        } catch (\LogicException $e) {
            return $this->json($this->apiErrorPayloadFactory->fromMessage($e->getMessage()), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($this->serializeApiTicket($ticket));
    }

    #[Route('/tickets/{id}/resume', name: 'ticket_resume_api', methods: ['POST'])]
    #[Route('/ticket-tasks/{id}/resume', name: 'ticket_task_resume_api', methods: ['POST'])]
    public function resume(string $id, Request $request): JsonResponse
    {
        $ticket = $this->ticketService->findById($id);
        if ($ticket !== null) {
            if (($response = $this->requireProjectTeamForProgress($ticket->getProject())) !== null) {
                return $response;
            }

            if (!$ticket->isStory()) {
                return $this->json($this->apiErrorPayloadFactory->create('tickets.rework.error.resume_unavailable'), Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            try {
                $result = $this->storyExecutionService->execute($ticket, $ticket->getAssignedAgent());
            } catch (\RuntimeException|\LogicException $e) {
                return $this->json($this->apiErrorPayloadFactory->fromMessage($e->getMessage()), Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return $this->json([
                'ticket' => $this->serializeApiTicket($ticket),
                'agent' => ['id' => (string) $result['agent']->getId(), 'name' => $result['agent']->getName()],
                'skill' => $result['skill'],
            ]);
        }

        $task = $this->ticketTaskService->findById($id);
        if ($task === null) {
            return $this->json($this->apiErrorPayloadFactory->create('tickets.error.not_found'), Response::HTTP_NOT_FOUND);
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

    #[Route('/tickets/{id}/rework-targets', name: 'ticket_rework_targets_api', methods: ['GET'])]
    public function reworkTargets(string $id): JsonResponse
    {
        $ticket = $this->ticketService->findById($id);
        if ($ticket === null) {
            return $this->json($this->apiErrorPayloadFactory->create('tickets.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        if (($response = $this->requireProjectTeamForProgress($ticket->getProject())) !== null) {
            return $response;
        }

        if (!$ticket->isStory()) {
            return $this->json($this->apiErrorPayloadFactory->create('tickets.rework.error.step_unavailable'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($this->storyExecutionService->listReworkTargets($ticket));
    }

    #[Route('/tickets/{id}/rework', name: 'ticket_rework_api', methods: ['POST'])]
    public function rework(string $id, Request $request): JsonResponse
    {
        $ticket = $this->ticketService->findById($id);
        if ($ticket === null) {
            return $this->json($this->apiErrorPayloadFactory->create('tickets.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        if (($response = $this->requireProjectTeamForProgress($ticket->getProject())) !== null) {
            return $response;
        }

        if (!$ticket->isStory()) {
            return $this->json($this->apiErrorPayloadFactory->create('tickets.rework.error.step_unavailable'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $data = $request->toArray();
        $targetKey = trim((string) ($data['targetKey'] ?? ''));
        $objective = trim((string) ($data['objective'] ?? ''));
        $note = isset($data['note']) ? trim((string) $data['note']) : null;

        if ($targetKey === '' || $objective === '') {
            return $this->json($this->apiErrorPayloadFactory->create('tickets.rework.validation.target_objective_required'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = $this->storyExecutionService->rework($ticket, $targetKey, $objective, $note);
        } catch (\RuntimeException $e) {
            return $this->json($this->apiErrorPayloadFactory->fromMessage($e->getMessage()), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'ticket' => $this->serializeApiTicket($ticket),
            'agent' => ['id' => (string) $result['agent']->getId(), 'name' => $result['agent']->getName()],
            'skill' => $result['skill'],
            'targetKey' => $result['targetKey'],
        ]);
    }

    #[Route('/tickets/{id}/execute', name: 'ticket_execute_agents_api', methods: ['GET'])]
    #[Route('/ticket-tasks/{id}/execute', name: 'ticket_task_execute_agents_api', methods: ['GET'])]
    public function executeAgents(string $id): JsonResponse
    {
        $ticket = $this->ticketService->findById($id);
        if ($ticket !== null) {
            if (($response = $this->requireProjectTeamForProgress($ticket->getProject())) !== null) {
                return $response;
            }

            if (!$ticket->isStory()) {
                return $this->json($this->apiErrorPayloadFactory->create('tickets.execution.error.unsupported_story_or_bug'), Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            if (!$this->storyExecutionService->canExecute($ticket)) {
                return $this->json($this->apiErrorPayloadFactory->create('tickets.execution.error.no_automatic_execution'), Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $agents = $this->storyExecutionService->availableAgents($ticket);

            return $this->json(array_map(fn($a) => [
                'id' => (string) $a->getId(),
                'name' => $a->getName(),
                'role' => $a->getRole() ? ['slug' => $a->getRole()->getSlug(), 'name' => $a->getRole()->getName()] : null,
            ], $agents));
        }

        $task = $this->ticketTaskService->findById($id);
        if ($task === null) {
            return $this->json($this->apiErrorPayloadFactory->create('tickets.error.not_found'), Response::HTTP_NOT_FOUND);
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

    #[Route('/tickets/{id}/execute', name: 'ticket_execute_api', methods: ['POST'])]
    #[Route('/ticket-tasks/{id}/execute', name: 'ticket_task_execute_api', methods: ['POST'])]
    public function execute(string $id, Request $request): JsonResponse
    {
        $ticket = $this->ticketService->findById($id);
        $data = $request->toArray();
        $agent = null;
        if (!empty($data['agentId'])) {
            $agent = $this->agentRepository->find($data['agentId']);
            if ($agent === null) {
                return $this->json($this->apiErrorPayloadFactory->create('tickets.execution.error.agent_not_found'), Response::HTTP_NOT_FOUND);
            }
        }

        if ($ticket !== null) {
            if (($response = $this->requireProjectTeamForProgress($ticket->getProject())) !== null) {
                return $response;
            }

            if (!$ticket->isStory()) {
                return $this->json($this->apiErrorPayloadFactory->create('tickets.execution.error.unsupported_story_or_bug'), Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            try {
                $result = $this->storyExecutionService->execute($ticket, $agent, TaskExecutionTrigger::Manual);
            } catch (\RuntimeException|\LogicException $e) {
                return $this->json($this->apiErrorPayloadFactory->fromMessage($e->getMessage()), Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return $this->json([
                'ticket' => $this->serializeApiTicket($ticket),
                'agent' => ['id' => (string) $result['agent']->getId(), 'name' => $result['agent']->getName()],
                'skill' => $result['skill'],
            ]);
        }

        $task = $this->ticketTaskService->findById($id);
        if ($task === null) {
            return $this->json($this->apiErrorPayloadFactory->create('tickets.error.not_found'), Response::HTTP_NOT_FOUND);
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

    #[Route('/tickets/{id}', name: 'ticket_delete_api', methods: ['DELETE'])]
    public function deleteTicket(string $id): JsonResponse
    {
        $ticket = $this->ticketService->findById($id);
        if ($ticket === null) {
            return $this->json($this->apiErrorPayloadFactory->create('tickets.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $this->ticketService->delete($ticket);

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/ticket-tasks/{id}', name: 'ticket_task_delete_api', methods: ['DELETE'])]
    public function deleteTicketTask(string $id): JsonResponse
    {
        $task = $this->ticketTaskService->findById($id);
        if ($task === null) {
            return $this->json($this->apiErrorPayloadFactory->create('tickets.error.not_found'), Response::HTTP_NOT_FOUND);
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

        $payload = [
            'id' => (string) $ticket->getId(),
            'projectId' => (string) $ticket->getProject()->getId(),
            'feature' => $ticket->getFeature() ? ['id' => (string) $ticket->getFeature()->getId(), 'name' => $ticket->getFeature()->getName()] : null,
            'type' => $ticket->getType()->value,
            'title' => $ticket->getTitle(),
            'description' => $ticket->getDescription(),
            'status' => $ticket->getStatus()->value,
            'storyStatus' => $ticket->getStoryStatus()?->value,
            'storyStatusAllowedTransitions' => $ticket->getStoryStatus()
                ? array_map(fn($status) => $status->value, $ticket->getStoryStatus()->allowedTransitions())
                : [],
            'priority' => $ticket->getPriority()->value,
            'progress' => $ticket->getProgress(),
            'branchName' => $ticket->getBranchName(),
            'branchUrl' => $this->vcsRepositoryUrl->buildBranchUrl($ticket->getProject()->getRepositoryUrl(), $ticket->getBranchName()),
            'workflowStep' => $ticket->getWorkflowStep() ? [
                'id' => (string) $ticket->getWorkflowStep()->getId(),
                'key' => $ticket->getWorkflowStep()->getKey(),
                'name' => $ticket->getWorkflowStep()->getName(),
                'storyStatusTrigger' => $ticket->getWorkflowStep()->getStoryStatusTrigger()?->value,
            ] : null,
            'assignedAgent' => $ticket->getAssignedAgent() ? ['id' => (string) $ticket->getAssignedAgent()->getId(), 'name' => $ticket->getAssignedAgent()->getName()] : null,
            'assignedRole' => $ticket->getAssignedRole() ? ['id' => (string) $ticket->getAssignedRole()->getId(), 'slug' => $ticket->getAssignedRole()->getSlug(), 'name' => $ticket->getAssignedRole()->getName()] : null,
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
                fn(TicketTask $task) => $this->serializeApiTicketTask($task),
                $activeStepTasks,
            );
        }

        if ($includeAllTasks) {
            $payload['tasks'] = array_map(
                fn(TicketTask $task) => $this->serializeApiTicketTask($task),
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
    ): array {
        $dependencies = $this->ticketTaskDependencyRepository->findByTicketTask($task);
        $children = $includeChildren ? $this->ticketTaskService->findChildren($task) : [];

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
                'storyStatusTrigger' => $task->getWorkflowStep()->getStoryStatusTrigger()?->value,
            ] : null,
            'agentAction' => [
                'id' => (string) $task->getAgentAction()->getId(),
                'key' => $task->getAgentAction()->getKey(),
                'label' => $task->getAgentAction()->getLabel(),
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
            $this->apiErrorPayloadFactory->create('projects.progress.error.team_required'),
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
