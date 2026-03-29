<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\StoryStatus;
use App\Enum\TaskExecutionTrigger;
use App\Enum\TaskPriority;
use App\Enum\TaskStatus;
use App\Enum\TaskType;
use App\Repository\AgentRepository;
use App\Repository\TaskExecutionRepository;
use App\Repository\TaskLogRepository;
use App\Service\ApiErrorPayloadFactory;
use App\Service\ProjectService;
use App\Service\StoryExecutionService;
use App\Service\TaskService;
use App\Service\TokenUsageService;
use App\Service\VcsRepositoryUrlService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class TaskController extends AbstractController
{
    public function __construct(
        private readonly TaskService           $taskService,
        private readonly ProjectService        $projectService,
        private readonly StoryExecutionService $storyExecutionService,
        private readonly AgentRepository       $agentRepository,
        private readonly TaskExecutionRepository $taskExecutionRepository,
        private readonly TaskLogRepository     $taskLogRepository,
        private readonly TokenUsageService     $tokenUsageService,
        private readonly VcsRepositoryUrlService $vcsRepositoryUrl,
        private readonly ApiErrorPayloadFactory $apiErrorPayloadFactory,
    ) {}

    #[Route('/projects/{projectId}/tasks', name: 'task_list', methods: ['GET'])]
    public function list(string $projectId): JsonResponse
    {
        $project = $this->projectService->findById($projectId);
        if ($project === null) {
            return $this->json($this->apiErrorPayloadFactory->create('projects.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $tasks = $this->taskService->findByProject($project);
        $childrenByParent = $this->taskService->findChildrenGroupedByParent($tasks);

        return $this->json(array_map(
            fn($t) => array_merge($this->serialize($t), [
                'children' => array_map(fn($child) => $this->serialize($child), $childrenByParent[(string) $t->getId()] ?? []),
            ]),
            $tasks,
        ));
    }

    #[Route('/projects/{projectId}/tasks', name: 'task_create', methods: ['POST'])]
    public function create(string $projectId, Request $request): JsonResponse
    {
        $project = $this->projectService->findById($projectId);
        if ($project === null) {
            return $this->json($this->apiErrorPayloadFactory->create('projects.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $data = $request->toArray();
        if (empty($data['title'])) {
            return $this->json($this->apiErrorPayloadFactory->create('tasks.validation.title_required'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $type     = TaskType::from($data['type'] ?? TaskType::Task->value);
        $priority = TaskPriority::from($data['priority'] ?? TaskPriority::Medium->value);

        $task = $this->taskService->create(
            $project,
            $type,
            $data['title'],
            $data['description'] ?? null,
            $priority,
            $data['featureId'] ?? null,
            $data['parentId'] ?? null,
            $data['assignedAgentId'] ?? null,
        );

        return $this->json($this->serialize($task), Response::HTTP_CREATED);
    }

    #[Route('/projects/{projectId}/requests', name: 'project_request_create', methods: ['POST'])]
    public function createRequest(string $projectId, Request $request): JsonResponse
    {
        $project = $this->projectService->findById($projectId);
        if ($project === null) {
            return $this->json($this->apiErrorPayloadFactory->create('projects.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $data = $request->toArray();
        if (empty($data['title'])) {
            return $this->json($this->apiErrorPayloadFactory->create('tasks.validation.title_required'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $priority = TaskPriority::from($data['priority'] ?? TaskPriority::Medium->value);
        $task = $this->taskService->createProductOwnerRequest(
            $project,
            $data['title'],
            $data['description'] ?? null,
            $priority,
        );

        $dispatchError = null;
        try {
            if ($this->storyExecutionService->canExecute($task)) {
                $this->storyExecutionService->execute($task, null, TaskExecutionTrigger::Auto);
            }
        } catch (\RuntimeException|\LogicException $e) {
            $dispatchError = $e->getMessage();
        }

        return $this->json(array_merge(
            $this->serialize($task),
            $dispatchError !== null ? ['dispatchError' => $dispatchError] : [],
        ), Response::HTTP_CREATED);
    }

    #[Route('/tasks/{id}', name: 'task_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $task = $this->taskService->findById($id);
        if ($task === null) {
            return $this->json($this->apiErrorPayloadFactory->create('tasks.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $children    = $this->taskService->findChildren($task);
        $executions  = $this->taskExecutionRepository->findByTask($task);
        $logs        = $this->taskLogRepository->findByTask($task);
        $tokenUsage  = $this->tokenUsageService->findByTask($task);

        return $this->json(array_merge($this->serialize($task), [
            'children'   => array_map(fn($c) => $this->serialize($c), $children),
            'executions' => array_map(fn($execution) => $this->serializeExecution($execution), $executions),
            'logs'       => array_map(fn($l) => $this->serializeLog($l), $logs),
            'tokenUsage' => $tokenUsage,
        ]));
    }

    #[Route('/tasks/{id}', name: 'task_update', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $task = $this->taskService->findById($id);
        if ($task === null) {
            return $this->json($this->apiErrorPayloadFactory->create('tasks.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $data     = $request->toArray();
        $priority = isset($data['priority']) ? TaskPriority::from($data['priority']) : $task->getPriority();

        $this->taskService->update(
            $task,
            $data['title'] ?? $task->getTitle(),
            $data['description'] ?? null,
            $priority,
            $data['featureId'] ?? null,
            $data['assignedAgentId'] ?? null,
        );
        return $this->json($this->serialize($task));
    }

    #[Route('/tasks/{id}/status', name: 'task_status', methods: ['PATCH'])]
    public function changeStatus(string $id, Request $request): JsonResponse
    {
        $task = $this->taskService->findById($id);
        if ($task === null) {
            return $this->json($this->apiErrorPayloadFactory->create('tasks.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $data = $request->toArray();
        if (empty($data['status'])) {
            return $this->json($this->apiErrorPayloadFactory->create('tasks.validation.status_required'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->taskService->changeStatus($task, TaskStatus::from($data['status']));
        return $this->json($this->serialize($task));
    }

    #[Route('/tasks/{id}/progress', name: 'task_progress', methods: ['PATCH'])]
    public function updateProgress(string $id, Request $request): JsonResponse
    {
        $task = $this->taskService->findById($id);
        if ($task === null) {
            return $this->json($this->apiErrorPayloadFactory->create('tasks.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $data = $request->toArray();
        $this->taskService->updateProgress($task, (int) ($data['progress'] ?? 0));
        return $this->json($this->serialize($task));
    }

    #[Route('/tasks/{id}/priority', name: 'task_reprioritize', methods: ['PATCH'])]
    public function reprioritize(string $id, Request $request): JsonResponse
    {
        $task = $this->taskService->findById($id);
        if ($task === null) {
            return $this->json($this->apiErrorPayloadFactory->create('tasks.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $data = $request->toArray();
        if (empty($data['priority'])) {
            return $this->json($this->apiErrorPayloadFactory->create('tasks.validation.priority_required'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->taskService->reprioritize($task, TaskPriority::from($data['priority']));
        return $this->json($this->serialize($task));
    }

    #[Route('/tasks/{id}/validate', name: 'task_validate', methods: ['POST'])]
    public function validate(string $id): JsonResponse
    {
        $task = $this->taskService->findById($id);
        if ($task === null) {
            return $this->json($this->apiErrorPayloadFactory->create('tasks.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $this->taskService->validate($task);
        return $this->json($this->serialize($task));
    }

    #[Route('/tasks/{id}/reject', name: 'task_reject', methods: ['POST'])]
    public function reject(string $id, Request $request): JsonResponse
    {
        $task = $this->taskService->findById($id);
        if ($task === null) {
            return $this->json($this->apiErrorPayloadFactory->create('tasks.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $data = $request->toArray();
        $this->taskService->reject($task, $data['reason'] ?? null);
        return $this->json($this->serialize($task));
    }

    #[Route('/tasks/{id}/request-validation', name: 'task_request_validation', methods: ['POST'])]
    public function requestValidation(string $id, Request $request): JsonResponse
    {
        $task = $this->taskService->findById($id);
        if ($task === null) {
            return $this->json($this->apiErrorPayloadFactory->create('tasks.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $data = $request->toArray();
        $this->taskService->requestValidation($task, $data['comment'] ?? null);
        return $this->json($this->serialize($task));
    }

    #[Route('/tasks/{id}/comments', name: 'task_comment_create', methods: ['POST'])]
    public function createComment(string $id, Request $request): JsonResponse
    {
        $task = $this->taskService->findById($id);
        if ($task === null) {
            return $this->json($this->apiErrorPayloadFactory->create('tasks.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $data = $request->toArray();
        $content = trim((string) ($data['content'] ?? ''));
        if ($content === '') {
            return $this->json($this->apiErrorPayloadFactory->create('tasks.validation.content_required'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $log = $this->taskService->addComment(
                task: $task,
                content: $content,
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

        return $this->json($this->serializeLog($log), Response::HTTP_CREATED);
    }

    #[Route('/tasks/{id}/story-transition', name: 'task_story_transition', methods: ['POST'])]
    public function storyTransition(string $id, Request $request): JsonResponse
    {
        $task = $this->taskService->findById($id);
        if ($task === null) {
            return $this->json($this->apiErrorPayloadFactory->create('tasks.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        if (!$task->isStory()) {
            return $this->json($this->apiErrorPayloadFactory->create('tasks.story.error.unsupported_user_story_or_bug'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $data = $request->toArray();
        if (empty($data['status'])) {
            return $this->json($this->apiErrorPayloadFactory->create('tasks.validation.status_required'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $next = StoryStatus::tryFrom($data['status']);
        if ($next === null) {
            return $this->json($this->apiErrorPayloadFactory->create('tasks.story.error.unknown_status', ['%status%' => (string) $data['status']]), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->taskService->transitionStory($task, $next);
        } catch (\LogicException $e) {
            return $this->json($this->apiErrorPayloadFactory->fromMessage($e->getMessage()), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($this->serialize($task));
    }

    #[Route('/tasks/{id}/resume', name: 'task_resume', methods: ['POST'])]
    public function resume(string $id): JsonResponse
    {
        $task = $this->taskService->findById($id);
        if ($task === null) {
            return $this->json($this->apiErrorPayloadFactory->create('tasks.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        if (!$task->isStory()) {
            return $this->json($this->apiErrorPayloadFactory->create('tasks.rework.error.resume_unavailable'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = $this->storyExecutionService->execute($task, $task->getAssignedAgent());
        } catch (\RuntimeException $e) {
            return $this->json($this->apiErrorPayloadFactory->fromMessage($e->getMessage()), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\LogicException $e) {
            return $this->json($this->apiErrorPayloadFactory->fromMessage($e->getMessage()), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'task'  => $this->serialize($task),
            'agent' => ['id' => (string) $result['agent']->getId(), 'name' => $result['agent']->getName()],
            'skill' => $result['skill'],
        ]);
    }

    /**
     * Returns the supported replayable agent stages for this story or bug.
     */
    #[Route('/tasks/{id}/rework-targets', name: 'task_rework_targets', methods: ['GET'])]
    public function reworkTargets(string $id): JsonResponse
    {
        $task = $this->taskService->findById($id);
        if ($task === null) {
            return $this->json($this->apiErrorPayloadFactory->create('tasks.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        if (!$task->isStory()) {
            return $this->json($this->apiErrorPayloadFactory->create('tasks.rework.error.step_unavailable'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($this->storyExecutionService->listReworkTargets($task));
    }

    /**
     * Sends the story back to a supported replayable agent stage with an explicit objective and note.
     */
    #[Route('/tasks/{id}/rework', name: 'task_rework', methods: ['POST'])]
    public function rework(string $id, Request $request): JsonResponse
    {
        $task = $this->taskService->findById($id);
        if ($task === null) {
            return $this->json($this->apiErrorPayloadFactory->create('tasks.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        if (!$task->isStory()) {
            return $this->json($this->apiErrorPayloadFactory->create('tasks.rework.error.step_unavailable'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $data = $request->toArray();
        $targetKey = trim((string) ($data['targetKey'] ?? ''));
        $objective = trim((string) ($data['objective'] ?? ''));
        $note = isset($data['note']) ? trim((string) $data['note']) : null;

        if ($targetKey === '' || $objective === '') {
            return $this->json($this->apiErrorPayloadFactory->create('tasks.rework.validation.target_objective_required'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = $this->storyExecutionService->rework($task, $targetKey, $objective, $note);
        } catch (\RuntimeException $e) {
            return $this->json($this->apiErrorPayloadFactory->fromMessage($e->getMessage()), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'task' => $this->serialize($task),
            'agent' => ['id' => (string) $result['agent']->getId(), 'name' => $result['agent']->getName()],
            'skill' => $result['skill'],
            'targetKey' => $result['targetKey'],
        ]);
    }

    /**
     * Returns the list of available agents for executing the story in its current status.
     * Used by the frontend to populate the agent selector before dispatching.
     */
    #[Route('/tasks/{id}/execute', name: 'task_execute_agents', methods: ['GET'])]
    public function executeAgents(string $id): JsonResponse
    {
        $task = $this->taskService->findById($id);
        if ($task === null) {
            return $this->json($this->apiErrorPayloadFactory->create('tasks.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        if (!$task->isStory()) {
            return $this->json($this->apiErrorPayloadFactory->create('tasks.execution.error.unsupported_story_or_bug'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!$this->storyExecutionService->canExecute($task)) {
            return $this->json($this->apiErrorPayloadFactory->create('tasks.execution.error.no_automatic_execution'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $agents = $this->storyExecutionService->availableAgents($task);

        return $this->json(array_map(fn($a) => [
            'id'   => (string) $a->getId(),
            'name' => $a->getName(),
            'role' => $a->getRole() ? ['slug' => $a->getRole()->getSlug(), 'name' => $a->getRole()->getName()] : null,
        ], $agents));
    }

    /**
     * Dispatches an AgentTaskMessage for this story.
     * Body: { agentId?: string }  — if omitted, the first available agent with the right role is used.
     * Transitions the story status if required by the execution config (e.g. approved → planning).
     */
    #[Route('/tasks/{id}/execute', name: 'task_execute', methods: ['POST'])]
    public function execute(string $id, Request $request): JsonResponse
    {
        $task = $this->taskService->findById($id);
        if ($task === null) {
            return $this->json($this->apiErrorPayloadFactory->create('tasks.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        if (!$task->isStory()) {
            return $this->json($this->apiErrorPayloadFactory->create('tasks.execution.error.unsupported_story_or_bug'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $data  = $request->toArray();
        $agent = null;

        if (!empty($data['agentId'])) {
            $agent = $this->agentRepository->find($data['agentId']);
            if ($agent === null) {
                return $this->json($this->apiErrorPayloadFactory->create('tasks.execution.error.agent_not_found'), Response::HTTP_NOT_FOUND);
            }
        }

        try {
            $result = $this->storyExecutionService->execute($task, $agent, TaskExecutionTrigger::Manual);
        } catch (\RuntimeException $e) {
            return $this->json($this->apiErrorPayloadFactory->fromMessage($e->getMessage()), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\LogicException $e) {
            return $this->json($this->apiErrorPayloadFactory->fromMessage($e->getMessage()), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'task'  => $this->serialize($task),
            'agent' => ['id' => (string) $result['agent']->getId(), 'name' => $result['agent']->getName()],
            'skill' => $result['skill'],
        ]);
    }

    #[Route('/tasks/{id}', name: 'task_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $task = $this->taskService->findById($id);
        if ($task === null) {
            return $this->json($this->apiErrorPayloadFactory->create('tasks.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $this->taskService->delete($task);
        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    private function serialize(\App\Entity\Task $task): array
    {
        $branchUrl = $this->vcsRepositoryUrl->buildBranchUrl(
            $task->getProject()->getRepositoryUrl(),
            $task->getBranchName(),
        );

        return [
            'id'            => (string) $task->getId(),
            'title'         => $task->getTitle(),
            'description'   => $task->getDescription(),
            'type'          => $task->getType()->value,
            'status'        => $task->getStatus()->value,
            'storyStatus'   => $task->getStoryStatus()?->value,
            'storyStatusAllowedTransitions' => $task->getStoryStatus()
                ? array_map(fn($s) => $s->value, $task->getStoryStatus()->allowedTransitions())
                : [],
            'priority'      => $task->getPriority()->value,
            'progress'      => $task->getProgress(),
            'branchName'    => $task->getBranchName(),
            'branchUrl'     => $branchUrl,
            'feature'       => $task->getFeature() ? ['id' => (string) $task->getFeature()->getId(), 'name' => $task->getFeature()->getName()] : null,
            'parent'        => $task->getParent() ? ['id' => (string) $task->getParent()->getId(), 'title' => $task->getParent()->getTitle()] : null,
            'workflowStep'  => $task->getWorkflowStep() ? [
                'id' => (string) $task->getWorkflowStep()->getId(),
                'name' => $task->getWorkflowStep()->getName(),
                'storyStatusTrigger' => $task->getWorkflowStep()->getStoryStatusTrigger()?->value,
            ] : null,
            'assignedAgent' => $task->getAssignedAgent() ? ['id' => (string) $task->getAssignedAgent()->getId(), 'name' => $task->getAssignedAgent()->getName()] : null,
            'assignedRole'  => $task->getAssignedRole() ? ['id' => (string) $task->getAssignedRole()->getId(), 'name' => $task->getAssignedRole()->getName(), 'slug' => $task->getAssignedRole()->getSlug()] : null,
            'addedBy'       => $task->getAddedBy() ? ['id' => (string) $task->getAddedBy()->getId(), 'name' => $task->getAddedBy()->getName()] : null,
            'createdAt'     => $task->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt'     => $task->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    private function serializeLog(\App\Entity\TaskLog $log): array
    {
        return [
            'id'             => (string) $log->getId(),
            'action'         => $log->getAction(),
            'kind'           => $log->getKind(),
            'authorType'     => $log->getAuthorType(),
            'authorName'     => $log->getAuthorName(),
            'requiresAnswer' => $log->requiresAnswer(),
            'replyToLogId'   => $log->getReplyToLogId()?->toRfc4122(),
            'metadata'       => $log->getMetadata(),
            'content'        => $log->getContent(),
            'createdAt'      => $log->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    private function serializeExecution(\App\Entity\TaskExecution $execution): array
    {
        return [
            'id' => (string) $execution->getId(),
            'traceRef' => $execution->getTraceRef(),
            'triggerType' => $execution->getTriggerType()->value,
            'workflowStepKey' => $execution->getWorkflowStepKey(),
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
            'attempts' => array_map(fn(\App\Entity\TaskExecutionAttempt $attempt) => [
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
}
