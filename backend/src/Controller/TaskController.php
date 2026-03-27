<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\StoryStatus;
use App\Enum\TaskPriority;
use App\Enum\TaskStatus;
use App\Enum\TaskType;
use App\Repository\AgentRepository;
use App\Repository\TaskLogRepository;
use App\Service\ProjectService;
use App\Service\StoryExecutionService;
use App\Service\TaskService;
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
        private readonly TaskLogRepository     $taskLogRepository,
    ) {}

    #[Route('/projects/{projectId}/tasks', name: 'task_list', methods: ['GET'])]
    public function list(string $projectId): JsonResponse
    {
        $project = $this->projectService->findById($projectId);
        if ($project === null) {
            return $this->json(['error' => 'Projet introuvable.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(array_map(
            fn($t) => $this->serialize($t),
            $this->taskService->findByProject($project),
        ));
    }

    #[Route('/projects/{projectId}/tasks', name: 'task_create', methods: ['POST'])]
    public function create(string $projectId, Request $request): JsonResponse
    {
        $project = $this->projectService->findById($projectId);
        if ($project === null) {
            return $this->json(['error' => 'Projet introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $data = $request->toArray();
        if (empty($data['title'])) {
            return $this->json(['error' => 'Le champ "title" est obligatoire.'], Response::HTTP_UNPROCESSABLE_ENTITY);
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

    #[Route('/tasks/{id}', name: 'task_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $task = $this->taskService->findById($id);
        if ($task === null) {
            return $this->json(['error' => 'Tâche introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $children = $this->taskService->findChildren($task);
        $logs     = $this->taskLogRepository->findByTask($task);

        return $this->json(array_merge($this->serialize($task), [
            'children' => array_map(fn($c) => $this->serialize($c), $children),
            'logs'     => array_map(fn($l) => [
                'action'    => $l->getAction(),
                'content'   => $l->getContent(),
                'createdAt' => $l->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ], $logs),
        ]));
    }

    #[Route('/tasks/{id}', name: 'task_update', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $task = $this->taskService->findById($id);
        if ($task === null) {
            return $this->json(['error' => 'Tâche introuvable.'], Response::HTTP_NOT_FOUND);
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
            return $this->json(['error' => 'Tâche introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $data = $request->toArray();
        if (empty($data['status'])) {
            return $this->json(['error' => 'Le champ "status" est obligatoire.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->taskService->changeStatus($task, TaskStatus::from($data['status']));
        return $this->json($this->serialize($task));
    }

    #[Route('/tasks/{id}/progress', name: 'task_progress', methods: ['PATCH'])]
    public function updateProgress(string $id, Request $request): JsonResponse
    {
        $task = $this->taskService->findById($id);
        if ($task === null) {
            return $this->json(['error' => 'Tâche introuvable.'], Response::HTTP_NOT_FOUND);
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
            return $this->json(['error' => 'Tâche introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $data = $request->toArray();
        if (empty($data['priority'])) {
            return $this->json(['error' => 'Le champ "priority" est obligatoire.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->taskService->reprioritize($task, TaskPriority::from($data['priority']));
        return $this->json($this->serialize($task));
    }

    #[Route('/tasks/{id}/validate', name: 'task_validate', methods: ['POST'])]
    public function validate(string $id): JsonResponse
    {
        $task = $this->taskService->findById($id);
        if ($task === null) {
            return $this->json(['error' => 'Tâche introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $this->taskService->validate($task);
        return $this->json($this->serialize($task));
    }

    #[Route('/tasks/{id}/reject', name: 'task_reject', methods: ['POST'])]
    public function reject(string $id, Request $request): JsonResponse
    {
        $task = $this->taskService->findById($id);
        if ($task === null) {
            return $this->json(['error' => 'Tâche introuvable.'], Response::HTTP_NOT_FOUND);
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
            return $this->json(['error' => 'Tâche introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $data = $request->toArray();
        $this->taskService->requestValidation($task, $data['comment'] ?? null);
        return $this->json($this->serialize($task));
    }

    #[Route('/tasks/{id}/story-transition', name: 'task_story_transition', methods: ['POST'])]
    public function storyTransition(string $id, Request $request): JsonResponse
    {
        $task = $this->taskService->findById($id);
        if ($task === null) {
            return $this->json(['error' => 'Tâche introuvable.'], Response::HTTP_NOT_FOUND);
        }

        if (!$task->isStory()) {
            return $this->json(['error' => 'Cette tâche n\'est pas une user story ou un bug.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $data = $request->toArray();
        if (empty($data['status'])) {
            return $this->json(['error' => 'Le champ "status" est obligatoire.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $next = StoryStatus::tryFrom($data['status']);
        if ($next === null) {
            return $this->json(['error' => "Statut story inconnu : {$data['status']}."], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->taskService->transitionStory($task, $next);
        } catch (\LogicException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($this->serialize($task));
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
            return $this->json(['error' => 'Tâche introuvable.'], Response::HTTP_NOT_FOUND);
        }

        if (!$task->isStory()) {
            return $this->json(['error' => 'Cette tâche n\'est pas une story ou un bug.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!$this->storyExecutionService->canExecute($task)) {
            return $this->json(['error' => 'Aucune exécution automatique disponible pour ce statut.'], Response::HTTP_UNPROCESSABLE_ENTITY);
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
            return $this->json(['error' => 'Tâche introuvable.'], Response::HTTP_NOT_FOUND);
        }

        if (!$task->isStory()) {
            return $this->json(['error' => 'Cette tâche n\'est pas une story ou un bug.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $data  = $request->toArray();
        $agent = null;

        if (!empty($data['agentId'])) {
            $agent = $this->agentRepository->find($data['agentId']);
            if ($agent === null) {
                return $this->json(['error' => 'Agent introuvable.'], Response::HTTP_NOT_FOUND);
            }
        }

        try {
            $result = $this->storyExecutionService->execute($task, $agent);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\LogicException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
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
            return $this->json(['error' => 'Tâche introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $this->taskService->delete($task);
        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    private function serialize(\App\Entity\Task $task): array
    {
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
            'feature'       => $task->getFeature() ? ['id' => (string) $task->getFeature()->getId(), 'name' => $task->getFeature()->getName()] : null,
            'parent'        => $task->getParent() ? ['id' => (string) $task->getParent()->getId(), 'title' => $task->getParent()->getTitle()] : null,
            'assignedAgent' => $task->getAssignedAgent() ? ['id' => (string) $task->getAssignedAgent()->getId(), 'name' => $task->getAssignedAgent()->getName()] : null,
            'assignedRole'  => $task->getAssignedRole() ? ['id' => (string) $task->getAssignedRole()->getId(), 'name' => $task->getAssignedRole()->getName(), 'slug' => $task->getAssignedRole()->getSlug()] : null,
            'addedBy'       => $task->getAddedBy() ? ['id' => (string) $task->getAddedBy()->getId(), 'name' => $task->getAddedBy()->getName()] : null,
            'createdAt'     => $task->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt'     => $task->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
