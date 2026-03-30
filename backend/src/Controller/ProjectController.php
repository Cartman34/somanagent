<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Controller;

use App\Repository\AuditLogRepository;
use App\Service\ApiErrorPayloadFactory;
use App\Service\ProjectService;
use App\Service\TokenUsageService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/projects')]
class ProjectController extends AbstractController
{
    public function __construct(
        private readonly ProjectService    $projectService,
        private readonly AuditLogRepository $auditLogRepository,
        private readonly TokenUsageService  $tokenUsageService,
        private readonly ApiErrorPayloadFactory $apiErrorPayloadFactory,
    ) {}

    #[Route('', name: 'project_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json(array_map(fn($p) => [
            'id'            => (string) $p->getId(),
            'name'          => $p->getName(),
            'description'   => $p->getDescription(),
            'repositoryUrl' => $p->getRepositoryUrl(),
            'team'          => $p->getTeam() ? ['id' => (string) $p->getTeam()->getId(), 'name' => $p->getTeam()->getName()] : null,
            'workflow'      => $p->getWorkflow() ? ['id' => (string) $p->getWorkflow()->getId(), 'name' => $p->getWorkflow()->getName()] : null,
            'modules'       => $p->getModules()->count(),
            'createdAt'     => $p->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt'     => $p->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ], $this->projectService->findAll()));
    }

    #[Route('', name: 'project_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = $request->toArray();
        if (empty($data['name'])) {
            return $this->json($this->apiErrorPayloadFactory->create('projects.validation.name_required'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $project = $this->projectService->create(
                $data['name'],
                $data['description'] ?? null,
                $data['repositoryUrl'] ?? null,
                $data['teamId'] ?? null,
                $data['workflowId'] ?? null,
            );
        } catch (\LogicException $exception) {
            return $this->json($this->apiErrorPayloadFactory->fromMessage($exception->getMessage()), Response::HTTP_CONFLICT);
        }

        return $this->json([
            'id'            => (string) $project->getId(),
            'name'          => $project->getName(),
            'description'   => $project->getDescription(),
            'repositoryUrl' => $project->getRepositoryUrl(),
            'team'          => $project->getTeam() ? ['id' => (string) $project->getTeam()->getId(), 'name' => $project->getTeam()->getName()] : null,
            'workflow'      => $project->getWorkflow() ? ['id' => (string) $project->getWorkflow()->getId(), 'name' => $project->getWorkflow()->getName()] : null,
            'createdAt'     => $project->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'project_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $project = $this->projectService->findById($id);
        if ($project === null) {
            return $this->json($this->apiErrorPayloadFactory->create('projects.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'id'            => (string) $project->getId(),
            'name'          => $project->getName(),
            'description'   => $project->getDescription(),
            'repositoryUrl' => $project->getRepositoryUrl(),
            'team'          => $project->getTeam() ? ['id' => (string) $project->getTeam()->getId(), 'name' => $project->getTeam()->getName()] : null,
            'workflow'      => $project->getWorkflow() ? ['id' => (string) $project->getWorkflow()->getId(), 'name' => $project->getWorkflow()->getName()] : null,
            'modules'       => array_map(fn($m) => [
                'id'            => (string) $m->getId(),
                'name'          => $m->getName(),
                'description'   => $m->getDescription(),
                'repositoryUrl' => $m->getRepositoryUrl(),
                'stack'         => $m->getStack(),
                'status'        => $m->getStatus()->value,
            ], $project->getModules()->toArray()),
            'createdAt'     => $project->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt'     => $project->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/{id}', name: 'project_update', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $project = $this->projectService->findById($id);
        if ($project === null) {
            return $this->json($this->apiErrorPayloadFactory->create('projects.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $data = $request->toArray();
        try {
            $this->projectService->update(
                $project,
                $data['name'] ?? $project->getName(),
                $data['description'] ?? null,
                $data['repositoryUrl'] ?? null,
                array_key_exists('teamId', $data) ? ($data['teamId'] ?: null) : ($project->getTeam() ? (string) $project->getTeam()->getId() : null),
                array_key_exists('workflowId', $data) ? ($data['workflowId'] ?: null) : ($project->getWorkflow() ? (string) $project->getWorkflow()->getId() : null),
            );
        } catch (\LogicException $exception) {
            return $this->json($this->apiErrorPayloadFactory->fromMessage($exception->getMessage()), Response::HTTP_CONFLICT);
        }
        return $this->json([
            'id'       => (string) $project->getId(),
            'name'     => $project->getName(),
            'team'     => $project->getTeam() ? ['id' => (string) $project->getTeam()->getId(), 'name' => $project->getTeam()->getName()] : null,
            'workflow' => $project->getWorkflow() ? ['id' => (string) $project->getWorkflow()->getId(), 'name' => $project->getWorkflow()->getName()] : null,
        ]);
    }

    #[Route('/{id}', name: 'project_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $project = $this->projectService->findById($id);
        if ($project === null) {
            return $this->json($this->apiErrorPayloadFactory->create('projects.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $this->projectService->delete($project);
        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Returns the audit log entries scoped to the project and its tasks, paginated.
     */
    #[Route('/{id}/audit', name: 'project_audit', methods: ['GET'])]
    public function audit(string $id, Request $request): JsonResponse
    {
        $project = $this->projectService->findById($id);
        if ($project === null) {
            return $this->json($this->apiErrorPayloadFactory->create('projects.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $page  = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 25)));

        $logs  = $this->auditLogRepository->findByProject($project, $limit, ($page - 1) * $limit);
        $total = $this->auditLogRepository->countByProject($project);

        return $this->json([
            'data'  => array_map(fn($log) => [
                'id'         => (string) $log->getId(),
                'action'     => $log->getAction()->value,
                'entityType' => $log->getEntityType(),
                'entityId'   => $log->getEntityId(),
                'data'       => $log->getData(),
                'createdAt'  => $log->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ], $logs),
            'total' => $total,
            'page'  => $page,
            'limit' => $limit,
        ]);
    }

    /**
     * Returns token usage for this project: a summary by agent and recent individual entries.
     */
    #[Route('/{id}/tokens', name: 'project_tokens', methods: ['GET'])]
    public function tokens(string $id): JsonResponse
    {
        $project = $this->projectService->findById($id);
        if ($project === null) {
            return $this->json($this->apiErrorPayloadFactory->create('projects.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->tokenUsageService->getProjectTokens($project));
    }

    // --- Modules ---

    #[Route('/{id}/modules', name: 'module_create', methods: ['POST'])]
    public function addModule(string $id, Request $request): JsonResponse
    {
        $project = $this->projectService->findById($id);
        if ($project === null) {
            return $this->json($this->apiErrorPayloadFactory->create('projects.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $data = $request->toArray();
        if (empty($data['name'])) {
            return $this->json($this->apiErrorPayloadFactory->create('projects.validation.name_required'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $module = $this->projectService->addModule($project, $data['name'], $data['description'] ?? null, $data['repositoryUrl'] ?? null, $data['stack'] ?? null);
        return $this->json([
            'id'            => (string) $module->getId(),
            'name'          => $module->getName(),
            'repositoryUrl' => $module->getRepositoryUrl(),
            'status'        => $module->getStatus()->value,
        ], Response::HTTP_CREATED);
    }

    #[Route('/modules/{id}', name: 'module_update', methods: ['PUT'])]
    public function updateModule(string $id, Request $request): JsonResponse
    {
        $module = $this->projectService->findModuleById($id);
        if ($module === null) {
            return $this->json($this->apiErrorPayloadFactory->create('projects.modules.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $data   = $request->toArray();
        $module = $this->projectService->updateModule($module, $data['name'] ?? $module->getName(), $data['description'] ?? null, $data['repositoryUrl'] ?? null, $data['stack'] ?? null);
        return $this->json(['id' => (string) $module->getId(), 'name' => $module->getName()]);
    }

    #[Route('/modules/{id}', name: 'module_delete', methods: ['DELETE'])]
    public function deleteModule(string $id): JsonResponse
    {
        $module = $this->projectService->findModuleById($id);
        if ($module === null) {
            return $this->json($this->apiErrorPayloadFactory->create('projects.modules.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $this->projectService->deleteModule($module);
        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
