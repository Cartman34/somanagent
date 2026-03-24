<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ProjectService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/projects')]
class ProjectController extends AbstractController
{
    public function __construct(private readonly ProjectService $projectService) {}

    #[Route('', name: 'project_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $projects = $this->projectService->findAll();

        return $this->json(array_map(fn($p) => [
            'id'          => (string) $p->getId(),
            'name'        => $p->getName(),
            'description' => $p->getDescription(),
            'modules'     => $p->getModules()->count(),
            'createdAt'   => $p->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt'   => $p->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ], $projects));
    }

    #[Route('', name: 'project_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = $request->toArray();

        if (empty($data['name'])) {
            return $this->json(['error' => 'Le champ "name" est obligatoire.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $project = $this->projectService->create($data['name'], $data['description'] ?? null);

        return $this->json([
            'id'          => (string) $project->getId(),
            'name'        => $project->getName(),
            'description' => $project->getDescription(),
            'createdAt'   => $project->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'project_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $project = $this->projectService->findById($id);

        if ($project === null) {
            return $this->json(['error' => 'Projet introuvable.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'id'          => (string) $project->getId(),
            'name'        => $project->getName(),
            'description' => $project->getDescription(),
            'modules'     => array_map(fn($m) => [
                'id'            => (string) $m->getId(),
                'name'          => $m->getName(),
                'description'   => $m->getDescription(),
                'repositoryUrl' => $m->getRepositoryUrl(),
                'stack'         => $m->getStack(),
                'status'        => $m->getStatus()->value,
            ], $project->getModules()->toArray()),
            'createdAt'   => $project->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt'   => $project->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/{id}', name: 'project_update', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $project = $this->projectService->findById($id);
        if ($project === null) {
            return $this->json(['error' => 'Projet introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $data    = $request->toArray();
        $project = $this->projectService->update($project, $data['name'] ?? $project->getName(), $data['description'] ?? null);

        return $this->json(['id' => (string) $project->getId(), 'name' => $project->getName()]);
    }

    #[Route('/{id}', name: 'project_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $project = $this->projectService->findById($id);
        if ($project === null) {
            return $this->json(['error' => 'Projet introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $this->projectService->delete($project);
        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    // --- Modules ---

    #[Route('/{id}/modules', name: 'module_create', methods: ['POST'])]
    public function addModule(string $id, Request $request): JsonResponse
    {
        $project = $this->projectService->findById($id);
        if ($project === null) {
            return $this->json(['error' => 'Projet introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $data   = $request->toArray();
        if (empty($data['name'])) {
            return $this->json(['error' => 'Le champ "name" est obligatoire.'], Response::HTTP_UNPROCESSABLE_ENTITY);
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
            return $this->json(['error' => 'Module introuvable.'], Response::HTTP_NOT_FOUND);
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
            return $this->json(['error' => 'Module introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $this->projectService->deleteModule($module);
        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
