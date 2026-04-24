<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Controller;

use App\Dto\Input\Role\AddRoleSkillDto;
use App\Dto\Input\Role\CreateRoleDto;
use App\Dto\Input\Role\UpdateRoleDto;
use App\Exception\ValidationException;
use App\Service\ApiErrorPayloadFactory;
use App\Service\RoleService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * REST controller managing specialization roles.
 */
#[Route('/api/roles')]
class RoleController extends AbstractApiController
{
    /**
     * Initializes the controller with its dependencies.
     */
    public function __construct(
        private readonly RoleService $roleService,
        ApiErrorPayloadFactory $apiErrorPayloadFactory,
    ) {
        parent::__construct($apiErrorPayloadFactory);
    }

    /**
     * Returns the list of all roles with their associated skills.
     */
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

    /**
     * Creates a new role.
     *
     * @param Request $request JSON body containing slug, name, and optional description
     */
    #[Route('', name: 'role_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        try {
            $dto = CreateRoleDto::fromArray($request->toArray());
        } catch (ValidationException $e) {
            return $this->json($this->apiErrorPayloadFactory->fromValidationException($e), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $role = $this->roleService->create($dto);
        return $this->json(['id' => (string) $role->getId(), 'slug' => $role->getSlug(), 'name' => $role->getName()], Response::HTTP_CREATED);
    }

    /**
     * Returns a single role by its ID with full skill details.
     *
     * @param string $id the role identifier
     */
    #[Route('/{id}', name: 'role_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $role = $this->roleService->findById($id);
        if ($role === null) {
            return $this->json($this->apiErrorPayloadFactory->create('role.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'id'          => (string) $role->getId(),
            'slug'        => $role->getSlug(),
            'name'        => $role->getName(),
            'description' => $role->getDescription(),
            'skills'      => array_map(fn($s) => [
                'id'          => (string) $s->getId(),
                'name'        => $s->getName(),
                'slug'        => $s->getSlug(),
                'description' => $s->getDescription(),
                'content'     => $s->getContent(),
                'filePath'    => $s->getFilePath(),
            ], $role->getSkills()->toArray()),
        ]);
    }

    /**
     * Updates an existing role.
     *
     * @param string  $id      the role identifier
     * @param Request $request JSON body containing optional slug, name, and description fields
     */
    #[Route('/{id}', name: 'role_update', methods: ['PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $role = $this->roleService->findById($id);
        if ($role === null) {
            return $this->json($this->apiErrorPayloadFactory->create('role.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $dto = UpdateRoleDto::fromArray($request->toArray());
        $this->roleService->update($role, $dto);
        return $this->json(['id' => (string) $role->getId(), 'slug' => $role->getSlug(), 'name' => $role->getName()]);
    }

    /**
     * Deletes a role by its ID.
     *
     * @param string $id the role identifier
     */
    #[Route('/{id}', name: 'role_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $role = $this->roleService->findById($id);
        if ($role === null) {
            return $this->json($this->apiErrorPayloadFactory->create('role.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $this->roleService->delete($role);
        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    // --- Role skills ---

    /**
     * Adds a skill to a role.
     *
     * @param string  $id      the role identifier
     * @param Request $request JSON body containing the skillId to add
     */
    #[Route('/{id}/skills', name: 'role_add_skill', methods: ['POST'])]
    public function addSkill(string $id, Request $request): JsonResponse
    {
        $role = $this->roleService->findById($id);
        if ($role === null) {
            return $this->json($this->apiErrorPayloadFactory->create('role.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        try {
            $dto = AddRoleSkillDto::fromArray($request->toArray());
        } catch (ValidationException $e) {
            return $this->json($this->apiErrorPayloadFactory->fromValidationException($e), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->roleService->addSkill($role, $dto->skillId);
        } catch (\InvalidArgumentException $e) {
            return $this->json($this->apiErrorPayloadFactory->fromMessage($e->getMessage()), Response::HTTP_NOT_FOUND);
        }

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Removes a skill from a role.
     *
     * @param string $id     the role identifier
     * @param string $skillId the skill identifier
     */
    #[Route('/{id}/skills/{skillId}', name: 'role_remove_skill', methods: ['DELETE'])]
    public function removeSkill(string $id, string $skillId): JsonResponse
    {
        $role = $this->roleService->findById($id);
        if ($role === null) {
            return $this->json($this->apiErrorPayloadFactory->create('role.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        try {
            $this->roleService->removeSkill($role, $skillId);
        } catch (\InvalidArgumentException $e) {
            return $this->json($this->apiErrorPayloadFactory->fromMessage($e->getMessage()), Response::HTTP_NOT_FOUND);
        }

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
