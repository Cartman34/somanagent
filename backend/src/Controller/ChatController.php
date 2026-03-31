<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ChatMessage;
use App\Service\ApiErrorPayloadFactory;
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
        private readonly ApiErrorPayloadFactory $apiErrorPayloadFactory,
    ) {}

    #[Route('/{agentId}', name: 'chat_history', methods: ['GET'])]
    public function history(string $projectId, string $agentId): JsonResponse
    {
        $project = $this->projectService->findById($projectId);
        $agent   = $this->agentService->findById($agentId);

        if ($project === null || $agent === null) {
            return $this->json($this->apiErrorPayloadFactory->create('chat.error.project_or_agent_not_found'), Response::HTTP_NOT_FOUND);
        }

        $messages = $this->chatService->getConversation($project, $agent);

        return $this->json(array_map(fn($m) => $this->serializeMessage($m), $messages));
    }

    #[Route('/{agentId}', name: 'chat_send', methods: ['POST'])]
    public function send(string $projectId, string $agentId, Request $request): JsonResponse
    {
        $project = $this->projectService->findById($projectId);
        $agent   = $this->agentService->findById($agentId);

        if ($project === null || $agent === null) {
            return $this->json($this->apiErrorPayloadFactory->create('chat.error.project_or_agent_not_found'), Response::HTTP_NOT_FOUND);
        }

        $data = $request->toArray();
        if (empty($data['content'])) {
            return $this->json($this->apiErrorPayloadFactory->create('chat.validation.content_required'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $exchange = $this->chatService->sendAndReceive($project, $agent, trim((string) $data['content']));

        return $this->json([
            'humanMessage' => $this->serializeMessage($exchange['human']),
            'agentMessage' => $this->serializeMessage($exchange['agent']),
        ], Response::HTTP_CREATED);
    }

    /**
     * Reply to an existing chat message in the given project / agent conversation.
     *
     * @param string $projectId Project UUID
     * @param string $agentId   Agent UUID
     */
    #[Route('/{agentId}/reply', name: 'chat_reply', methods: ['POST'])]
    public function reply(string $projectId, string $agentId, Request $request): JsonResponse
    {
        $project = $this->projectService->findById($projectId);
        $agent   = $this->agentService->findById($agentId);

        if ($project === null || $agent === null) {
            return $this->json($this->apiErrorPayloadFactory->create('chat.error.project_or_agent_not_found'), Response::HTTP_NOT_FOUND);
        }

        $data = $request->toArray();
        if (empty($data['content'])) {
            return $this->json($this->apiErrorPayloadFactory->create('chat.validation.content_required'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (empty($data['replyToMessageId'])) {
            return $this->json($this->apiErrorPayloadFactory->create('chat.validation.reply_to_required'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $message = $this->chatService->reply($project, $agent, trim((string) $data['content']), $data['replyToMessageId']);

        return $this->json($this->serializeMessage($message), Response::HTTP_CREATED);
    }

    /** @return array<string, mixed> */
    private function serializeMessage(ChatMessage $message): array
    {
        return [
            'id'              => (string) $message->getId(),
            'exchangeId'      => $message->getExchangeId(),
            'replyToMessageId' => $message->getReplyToMessageId()?->toRfc4122(),
            'author'          => $message->getAuthor()->value,
            'content'         => $message->getContent(),
            'isError'         => $message->isError(),
            'metadata'        => $message->getMetadata(),
            'createdAt'       => $message->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
