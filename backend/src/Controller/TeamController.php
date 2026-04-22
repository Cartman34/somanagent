<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Controller;

use App\Dto\Input\Team\AddTeamAgentDto;
use App\Dto\Input\Team\CreateTeamDto;
use App\Dto\Input\Team\UpdateTeamDto;
use App\Service\ApiErrorPayloadFactory;
use App\Service\TeamService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * REST controller managing teams and their agent membership.
 */
#[Route('/api/teams')]
class TeamController extends AbstractController
{
    /**
     * Initializes the controller with its dependencies.
     */
    public function __construct(
        private readonly TeamService $teamService,
        private readonly ApiErrorPayloadFactory $apiErrorPayloadFactory,
    ) {}

    /**
     * Lists all teams.
     */
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

    /**
     * Creates a new team.
     */
    #[Route('', name: 'team_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        try {
            $dto = CreateTeamDto::fromArray($request->toArray());
        } catch (\InvalidArgumentException) {
            return $this->json($this->apiErrorPayloadFactory->create('team.validation.name_required'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $team = $this->teamService->create($dto->name, $dto->description);
        return $this->json(['id' => (string) $team->getId(), 'name' => $team->getName()], Response::HTTP_CREATED);
    }

    /**
     * Retrieves a single team by ID with its agents.
     */
    #[Route('/{id}', name: 'team_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $team = $this->teamService->findById($id);
        if ($team === null) {
            return $this->json($this->apiErrorPayloadFactory->create('team.error.not_found'), Response::HTTP_NOT_FOUND);
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

    /**
     * Updates an existing team.
     */
    #[Route('/{id}', name: 'team_update', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $team = $this->teamService->findById($id);
        if ($team === null) {
            return $this->json($this->apiErrorPayloadFactory->create('team.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $dto = UpdateTeamDto::fromArray($request->toArray());
        $this->teamService->update($team, $dto->name ?? $team->getName(), $dto->description);
        return $this->json(['id' => (string) $team->getId(), 'name' => $team->getName()]);
    }

    /**
     * Deletes a team.
     */
    #[Route('/{id}', name: 'team_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $team = $this->teamService->findById($id);
        if ($team === null) {
            return $this->json($this->apiErrorPayloadFactory->create('team.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $this->teamService->delete($team);
        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    // --- Membres ---

    /**
     * Adds an agent to a team.
     */
    #[Route('/{id}/agents', name: 'team_add_agent', methods: ['POST'])]
    public function addAgent(string $id, Request $request): JsonResponse
    {
        $team = $this->teamService->findById($id);
        if ($team === null) {
            return $this->json($this->apiErrorPayloadFactory->create('team.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        try {
            $dto = AddTeamAgentDto::fromArray($request->toArray());
        } catch (\InvalidArgumentException) {
            return $this->json($this->apiErrorPayloadFactory->create('team.validation.agent_id_required'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $agent = $this->teamService->findAgentById($dto->agentId);
        if ($agent === null) {
            return $this->json($this->apiErrorPayloadFactory->create('agent.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $this->teamService->addAgent($team, $agent);
        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Removes an agent from a team.
     */
    #[Route('/{id}/agents/{agentId}', name: 'team_remove_agent', methods: ['DELETE'])]
    public function removeAgent(string $id, string $agentId): JsonResponse
    {
        $team = $this->teamService->findById($id);
        if ($team === null) {
            return $this->json($this->apiErrorPayloadFactory->create('team.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $agent = $this->teamService->findAgentById($agentId);
        if ($agent === null) {
            return $this->json($this->apiErrorPayloadFactory->create('agent.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $this->teamService->removeAgent($team, $agent);
        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
