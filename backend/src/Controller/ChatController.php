<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Controller;

use App\Dto\Input\Chat\ReplyChatMessageDto;
use App\Dto\Input\Chat\SendChatMessageDto;
use App\Dto\Input\Chat\UpdateChatMessageDto;
use App\Entity\ChatMessage;
use App\Exception\ValidationException;
use App\Service\ApiErrorPayloadFactory;
use App\Service\AgentService;
use App\Service\ChatService;
use App\Service\ProjectService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * REST controller managing chat conversations within a project.
 */
#[Route('/api/projects/{projectId}/chat')]
class ChatController extends AbstractApiController
{
    /**
     * Initializes the controller with the services required for project chat endpoints.
     */
    public function __construct(
        private readonly ChatService    $chatService,
        private readonly ProjectService $projectService,
        private readonly AgentService   $agentService,
        ApiErrorPayloadFactory $apiErrorPayloadFactory,
    ) {
        parent::__construct($apiErrorPayloadFactory);
    }

    /**
     * Returns the chat history for a given project and agent.
     */
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

    /**
     * Sends a new user message and returns both the stored human and agent replies.
     */
    #[Route('/{agentId}', name: 'chat_send', methods: ['POST'])]
    public function send(string $projectId, string $agentId, Request $request): JsonResponse
    {
        $project = $this->projectService->findById($projectId);
        $agent   = $this->agentService->findById($agentId);

        if ($project === null || $agent === null) {
            return $this->json($this->apiErrorPayloadFactory->create('chat.error.project_or_agent_not_found'), Response::HTTP_NOT_FOUND);
        }

        try {
            $dto = SendChatMessageDto::fromArray($request->toArray());
        } catch (ValidationException $e) {
            return $this->json($this->apiErrorPayloadFactory->fromValidationException($e), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $exchange = $this->chatService->sendAndReceive($project, $agent, $dto);

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

        try {
            $dto = ReplyChatMessageDto::fromArray($request->toArray());
        } catch (ValidationException $e) {
            return $this->json($this->apiErrorPayloadFactory->fromValidationException($e), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $message = $this->chatService->reply($project, $agent, $dto);

        return $this->json($this->serializeMessage($message), Response::HTTP_CREATED);
    }

    /**
     * Edits one existing human-authored chat message within the same project / agent conversation.
     */
    #[Route('/{agentId}/messages/{messageId}', name: 'chat_message_update', methods: ['PATCH'])]
    public function updateMessage(string $projectId, string $agentId, string $messageId, Request $request): JsonResponse
    {
        $project = $this->projectService->findById($projectId);
        $agent   = $this->agentService->findById($agentId);

        if ($project === null || $agent === null) {
            return $this->json($this->apiErrorPayloadFactory->create('chat.error.project_or_agent_not_found'), Response::HTTP_NOT_FOUND);
        }

        try {
            $dto = UpdateChatMessageDto::fromArray($request->toArray());
        } catch (ValidationException $e) {
            return $this->json($this->apiErrorPayloadFactory->fromValidationException($e), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $message = $this->chatService->editHumanMessage($project, $agent, $messageId, $dto);
        } catch (\InvalidArgumentException $e) {
            $key = $e->getMessage();
            if ($key === 'chat.error.message_not_found') {
                return $this->json($this->apiErrorPayloadFactory->create('chat.error.message_not_found'), Response::HTTP_NOT_FOUND);
            }

            return $this->json($this->apiErrorPayloadFactory->create('chat.error.message_not_editable'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($this->serializeMessage($message));
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
