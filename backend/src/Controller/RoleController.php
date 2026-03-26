<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\RoleService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/roles')]
class RoleController extends AbstractController
{
    public function __construct(private readonly RoleService $roleService) {}

    #[Route('', name: 'role_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json(array_map(fn($r) => [
            'id'          => (string) $r->getId(),
            'slug'        => $r->getSlug(),
            'name'        => $r->getName(),
            'description' => $r->getDescription(),
            'skills'      => array_map(fn($s) => [
                'id'   => (string) $s->getId(),
                'name' => $s->getName(),
            ], $r->getSkills()->toArray()),
        ], $this->roleService->findAll()));
    }

    #[Route('', name: 'role_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = $request->toArray();
        if (empty($data['slug']) || empty($data['name'])) {
            return $this->json(['error' => 'Les champs "slug" et "name" sont obligatoires.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $role = $this->roleService->create($data['slug'], $data['name'], $data['description'] ?? null);
        return $this->json(['id' => (string) $role->getId(), 'slug' => $role->getSlug(), 'name' => $role->getName()], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'role_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $role = $this->roleService->findById($id);
        if ($role === null) {
            return $this->json(['error' => 'Rôle introuvable.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'id'          => (string) $role->getId(),
            'slug'        => $role->getSlug(),
            'name'        => $role->getName(),
            'description' => $role->getDescription(),
            'skills'      => array_map(fn($s) => [
                'id'   => (string) $s->getId(),
                'name' => $s->getName(),
                'slug' => $s->getSlug(),
            ], $role->getSkills()->toArray()),
        ]);
    }

    #[Route('/{id}', name: 'role_update', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $role = $this->roleService->findById($id);
        if ($role === null) {
            return $this->json(['error' => 'Rôle introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $data = $request->toArray();
        $this->roleService->update(
            $role,
            $data['slug'] ?? $role->getSlug(),
            $data['name'] ?? $role->getName(),
            $data['description'] ?? null,
        );
        return $this->json(['id' => (string) $role->getId(), 'slug' => $role->getSlug(), 'name' => $role->getName()]);
    }

    #[Route('/{id}', name: 'role_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $role = $this->roleService->findById($id);
        if ($role === null) {
            return $this->json(['error' => 'Rôle introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $this->roleService->delete($role);
        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    // --- Skills du rôle ---

    #[Route('/{id}/skills', name: 'role_add_skill', methods: ['POST'])]
    public function addSkill(string $id, Request $request): JsonResponse
    {
        $role = $this->roleService->findById($id);
        if ($role === null) {
            return $this->json(['error' => 'Rôle introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $data = $request->toArray();
        if (empty($data['skillId'])) {
            return $this->json(['error' => 'Le champ "skillId" est obligatoire.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->roleService->addSkill($role, $data['skillId']);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        }

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/skills/{skillId}', name: 'role_remove_skill', methods: ['DELETE'])]
    public function removeSkill(string $id, string $skillId): JsonResponse
    {
        $role = $this->roleService->findById($id);
        if ($role === null) {
            return $this->json(['error' => 'Rôle introuvable.'], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->roleService->removeSkill($role, $skillId);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        }

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
