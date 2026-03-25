<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\ConnectorType;
use App\Service\AgentService;
use App\ValueObject\AgentConfig;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/agents')]
class AgentController extends AbstractController
{
    public function __construct(private readonly AgentService $agentService) {}

    #[Route('', name: 'agent_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json(array_map(fn($a) => [
            'id'             => (string) $a->getId(),
            'name'           => $a->getName(),
            'description'    => $a->getDescription(),
            'connector'      => $a->getConnector()->value,
            'connectorLabel' => $a->getConnector()->label(),
            'isActive'       => $a->isActive(),
            'role'           => $a->getRole() ? ['id' => (string) $a->getRole()->getId(), 'name' => $a->getRole()->getName()] : null,
            'config'         => $a->getAgentConfig()->toArray(),
            'createdAt'      => $a->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt'      => $a->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ], $this->agentService->findAll()));
    }

    #[Route('', name: 'agent_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = $request->toArray();
        if (empty($data['name'])) {
            return $this->json(['error' => 'Le champ "name" est obligatoire.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $connector = ConnectorType::from($data['connector'] ?? ConnectorType::ClaudeApi->value);
        $config    = AgentConfig::fromArray($data['config'] ?? ['model' => 'claude-sonnet-4-5']);

        $agent = $this->agentService->create($data['name'], $connector, $config, $data['description'] ?? null, $data['roleId'] ?? null);

        return $this->json(['id' => (string) $agent->getId(), 'name' => $agent->getName()], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'agent_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $agent = $this->agentService->findById($id);
        if ($agent === null) {
            return $this->json(['error' => 'Agent introuvable.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'id'          => (string) $agent->getId(),
            'name'        => $agent->getName(),
            'description' => $agent->getDescription(),
            'connector'   => $agent->getConnector()->value,
            'connectorLabel' => $agent->getConnector()->label(),
            'isActive'    => $agent->isActive(),
            'role'        => $agent->getRole() ? ['id' => (string) $agent->getRole()->getId(), 'name' => $agent->getRole()->getName()] : null,
            'config'      => $agent->getAgentConfig()->toArray(),
            'createdAt'   => $agent->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/{id}', name: 'agent_update', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $agent = $this->agentService->findById($id);
        if ($agent === null) {
            return $this->json(['error' => 'Agent introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $data      = $request->toArray();
        $connector = ConnectorType::from($data['connector'] ?? $agent->getConnector()->value);
        $config    = AgentConfig::fromArray($data['config'] ?? $agent->getAgentConfig()->toArray());

        $this->agentService->update($agent, $data['name'] ?? $agent->getName(), $data['description'] ?? null, $connector, $config, $data['roleId'] ?? null);
        return $this->json(['id' => (string) $agent->getId(), 'name' => $agent->getName()]);
    }

    #[Route('/{id}', name: 'agent_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $agent = $this->agentService->findById($id);
        if ($agent === null) {
            return $this->json(['error' => 'Agent introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $this->agentService->delete($agent);
        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
