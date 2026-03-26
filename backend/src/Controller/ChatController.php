<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AgentService;
use App\Service\ChatService;
use App\Service\ProjectService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/projects/{projectId}/chat')]
class ChatController extends AbstractController
{
    public function __construct(
        private readonly ChatService    $chatService,
        private readonly ProjectService $projectService,
        private readonly AgentService   $agentService,
    ) {}

    #[Route('/{agentId}', name: 'chat_history', methods: ['GET'])]
    public function history(string $projectId, string $agentId): JsonResponse
    {
        $project = $this->projectService->findById($projectId);
        $agent   = $this->agentService->findById($agentId);

        if ($project === null || $agent === null) {
            return $this->json(['error' => 'Projet ou agent introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $messages = $this->chatService->getConversation($project, $agent);

        return $this->json(array_map(fn($m) => [
            'id'        => (string) $m->getId(),
            'author'    => $m->getAuthor()->value,
            'content'   => $m->getContent(),
            'createdAt' => $m->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], $messages));
    }

    #[Route('/{agentId}', name: 'chat_send', methods: ['POST'])]
    public function send(string $projectId, string $agentId, Request $request): JsonResponse
    {
        $project = $this->projectService->findById($projectId);
        $agent   = $this->agentService->findById($agentId);

        if ($project === null || $agent === null) {
            return $this->json(['error' => 'Projet ou agent introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $data = $request->toArray();
        if (empty($data['content'])) {
            return $this->json(['error' => 'Le champ "content" est obligatoire.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $message = $this->chatService->sendHuman($project, $agent, $data['content']);

        return $this->json([
            'id'        => (string) $message->getId(),
            'author'    => $message->getAuthor()->value,
            'content'   => $message->getContent(),
            'createdAt' => $message->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], Response::HTTP_CREATED);
    }
}
