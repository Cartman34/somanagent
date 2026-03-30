<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Controller;

use App\Service\ApiErrorPayloadFactory;
use App\Service\TeamService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/teams')]
class TeamController extends AbstractController
{
    public function __construct(
        private readonly TeamService $teamService,
        private readonly ApiErrorPayloadFactory $apiErrorPayloadFactory,
    ) {}

    #[Route('', name: 'team_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json(array_map(fn($t) => [
            'id'          => (string) $t->getId(),
            'name'        => $t->getName(),
            'description' => $t->getDescription(),
            'agentCount'  => $t->getAgents()->count(),
            'createdAt'   => $t->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], $this->teamService->findAll()));
    }

    #[Route('', name: 'team_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = $request->toArray();
        if (empty($data['name'])) {
            return $this->json($this->apiErrorPayloadFactory->create('teams.validation.name_required'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $team = $this->teamService->create($data['name'], $data['description'] ?? null);
        return $this->json(['id' => (string) $team->getId(), 'name' => $team->getName()], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'team_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $team = $this->teamService->findById($id);
        if ($team === null) {
            return $this->json($this->apiErrorPayloadFactory->create('teams.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'id'          => (string) $team->getId(),
            'name'        => $team->getName(),
            'description' => $team->getDescription(),
            'agents'      => array_map(fn($a) => [
                'id'        => (string) $a->getId(),
                'name'      => $a->getName(),
                'isActive'  => $a->isActive(),
                'role'      => $a->getRole() ? ['id' => (string) $a->getRole()->getId(), 'name' => $a->getRole()->getName(), 'slug' => $a->getRole()->getSlug()] : null,
            ], $team->getAgents()->toArray()),
            'createdAt'   => $team->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt'   => $team->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/{id}', name: 'team_update', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $team = $this->teamService->findById($id);
        if ($team === null) {
            return $this->json($this->apiErrorPayloadFactory->create('teams.error.not_found'), Response::HTTP_NOT_FOUND);
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
            return $this->json($this->apiErrorPayloadFactory->create('teams.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $this->teamService->delete($team);
        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    // --- Membres ---

    #[Route('/{id}/agents', name: 'team_add_agent', methods: ['POST'])]
    public function addAgent(string $id, Request $request): JsonResponse
    {
        $team = $this->teamService->findById($id);
        if ($team === null) {
            return $this->json($this->apiErrorPayloadFactory->create('teams.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $data = $request->toArray();
        if (empty($data['agentId'])) {
            return $this->json($this->apiErrorPayloadFactory->create('teams.validation.agent_id_required'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $agent = $this->teamService->findAgentById($data['agentId']);
        if ($agent === null) {
            return $this->json($this->apiErrorPayloadFactory->create('agents.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $this->teamService->addAgent($team, $agent);
        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/agents/{agentId}', name: 'team_remove_agent', methods: ['DELETE'])]
    public function removeAgent(string $id, string $agentId): JsonResponse
    {
        $team = $this->teamService->findById($id);
        if ($team === null) {
            return $this->json($this->apiErrorPayloadFactory->create('teams.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $agent = $this->teamService->findAgentById($agentId);
        if ($agent === null) {
            return $this->json($this->apiErrorPayloadFactory->create('agents.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $this->teamService->removeAgent($team, $agent);
        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
