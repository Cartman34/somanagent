<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\TeamService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/teams')]
class TeamController extends AbstractController
{
    public function __construct(private readonly TeamService $teamService) {}

    #[Route('', name: 'team_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json(array_map(fn($t) => [
            'id'          => (string) $t->getId(),
            'name'        => $t->getName(),
            'description' => $t->getDescription(),
            'roles'       => $t->getRoles()->count(),
            'createdAt'   => $t->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], $this->teamService->findAll()));
    }

    #[Route('', name: 'team_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = $request->toArray();
        if (empty($data['name'])) {
            return $this->json(['error' => 'Le champ "name" est obligatoire.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $team = $this->teamService->create($data['name'], $data['description'] ?? null);
        return $this->json(['id' => (string) $team->getId(), 'name' => $team->getName()], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'team_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $team = $this->teamService->findById($id);
        if ($team === null) {
            return $this->json(['error' => 'Équipe introuvable.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'id'          => (string) $team->getId(),
            'name'        => $team->getName(),
            'description' => $team->getDescription(),
            'roles'       => array_map(fn($r) => [
                'id'          => (string) $r->getId(),
                'name'        => $r->getName(),
                'description' => $r->getDescription(),
                'skillSlug'   => $r->getSkillSlug(),
            ], $team->getRoles()->toArray()),
            'createdAt'   => $team->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt'   => $team->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/{id}', name: 'team_update', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $team = $this->teamService->findById($id);
        if ($team === null) {
            return $this->json(['error' => 'Équipe introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $data = $request->toArray();
        $this->teamService->update($team, $data['name'] ?? $team->getName(), $data['description'] ?? null);
        return $this->json(['id' => (string) $team->getId(), 'name' => $team->getName()]);
    }

    #[Route('/{id}', name: 'team_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $team = $this->teamService->findById($id);
        if ($team === null) {
            return $this->json(['error' => 'Équipe introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $this->teamService->delete($team);
        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    // --- Rôles ---

    #[Route('/{id}/roles', name: 'role_create', methods: ['POST'])]
    public function addRole(string $id, Request $request): JsonResponse
    {
        $team = $this->teamService->findById($id);
        if ($team === null) {
            return $this->json(['error' => 'Équipe introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $data = $request->toArray();
        if (empty($data['name'])) {
            return $this->json(['error' => 'Le champ "name" est obligatoire.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $role = $this->teamService->addRole($team, $data['name'], $data['description'] ?? null, $data['skillSlug'] ?? null);
        return $this->json(['id' => (string) $role->getId(), 'name' => $role->getName()], Response::HTTP_CREATED);
    }

    #[Route('/roles/{id}', name: 'role_update', methods: ['PUT'])]
    public function updateRole(string $id, Request $request): JsonResponse
    {
        $role = $this->teamService->findRoleById($id);
        if ($role === null) {
            return $this->json(['error' => 'Rôle introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $data = $request->toArray();
        $this->teamService->updateRole($role, $data['name'] ?? $role->getName(), $data['description'] ?? null, $data['skillSlug'] ?? null);
        return $this->json(['id' => (string) $role->getId(), 'name' => $role->getName()]);
    }

    #[Route('/roles/{id}', name: 'role_delete', methods: ['DELETE'])]
    public function deleteRole(string $id): JsonResponse
    {
        $role = $this->teamService->findRoleById($id);
        if ($role === null) {
            return $this->json(['error' => 'Rôle introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $this->teamService->removeRole($role->getTeam(), $role);
        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
