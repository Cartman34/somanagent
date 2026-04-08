<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Service;

use App\Entity\Agent;
use App\Entity\ChatMessage;
use App\Entity\Project;
use App\Entity\TokenUsage;
use App\Enum\AuditAction;
use App\Enum\ChatAuthor;
use App\Repository\ChatMessageRepository;
use Symfony\Component\Uid\Uuid;
use App\ValueObject\ConnectorRequest;
use App\ValueObject\Prompt;

/**
 * Manages chat conversations within a project, including agent interactions and message persistence.
 */
class ChatService
{
    /**
     * Initializes the service with its dependencies.
     */
    public function __construct(
        private readonly EntityService         $entityService,
        private readonly ChatMessageRepository $chatMessageRepository,
        private readonly ConnectorRegistry     $connectorRegistry,
        private readonly AgentContextBuilder   $contextBuilder,
    ) {}

    /**
     * Persists a human-authored chat message for the given project and agent.
     */
    public function sendHuman(Project $project, Agent $agent, string $content): ChatMessage
    {
        return $this->save($project, $agent, ChatAuthor::Human, $content);
    }

    /**
     * Persists an agent-authored chat message for the given project and agent.
     */
    public function sendAgent(Project $project, Agent $agent, string $content): ChatMessage
    {
        return $this->save($project, $agent, ChatAuthor::Agent, $content);
    }

    /**
     * @return array{human: ChatMessage, agent: ChatMessage}
     */
    public function sendAndReceive(Project $project, Agent $agent, string $content): array
    {
        $exchangeId = (string) Uuid::v7();
        $config     = $agent->getConnectorConfig();

        $human = $this->save(
            $project,
            $agent,
            ChatAuthor::Human,
            $content,
            $exchangeId,
            false,
            [
                'interaction' => 'project_chat',
                'connector'   => $agent->getConnector()->value,
                'model'       => $config->model,
            ],
        );

        try {
            $recentConversation = array_slice($this->getConversation($project, $agent, 12), -12);
            $prompt = Prompt::create('', $content, [
                ...$this->contextBuilder->buildForProjectChat($project, $agent, $recentConversation),
            ]);

            $response = $this->connectorRegistry
                ->getFor($agent->getConnector())
                ->sendRequest(ConnectorRequest::fromPrompt($prompt, ConnectorRequest::DEFAULT_WORKING_DIRECTORY), $config);

            $usage = new TokenUsage(
                agent: $agent,
                model: $config->model,
                inputTokens: $response->inputTokens,
                outputTokens: $response->outputTokens,
                durationMs: (int) $response->durationMs,
            );
            // Stage usage; flushed together with the agent message below.
            $this->entityService->persist($usage);

            $agentMessage = $this->save(
                $project,
                $agent,
                ChatAuthor::Agent,
                $response->content,
                $exchangeId,
                false,
                [
                    'interaction'   => 'project_chat',
                    'connector'     => $agent->getConnector()->value,
                    'model'         => $config->model,
                    'duration_ms'   => (int) $response->durationMs,
                    'input_tokens'  => $response->inputTokens,
                    'output_tokens' => $response->outputTokens,
                    ...$response->metadata,
                ],
            );
        } catch (\Throwable $e) {
            $agentMessage = $this->save(
                $project,
                $agent,
                ChatAuthor::Agent,
                $e->getMessage(),
                $exchangeId,
                true,
                [
                    'interaction' => 'project_chat',
                    'connector'   => $agent->getConnector()->value,
                    'model'       => $config->model,
                    'exception'   => $e::class,
                ],
            );
        }

        return ['human' => $human, 'agent' => $agentMessage];
    }

    /**
     * Persists and flushes a new ChatMessage together with any previously staged entities,
     * then writes an audit entry.
     *
     * @param array<string, mixed>|null $metadata
     */
    private function save(
        Project    $project,
        Agent      $agent,
        ChatAuthor $author,
        string     $content,
        ?string    $exchangeId       = null,
        bool       $isError          = false,
        ?array     $metadata         = null,
        ?\Symfony\Component\Uid\Uuid $replyToMessageId = null,
    ): ChatMessage {
        $message = new ChatMessage($project, $agent, $author, $content, $exchangeId, $isError, $metadata);
        if ($replyToMessageId !== null) {
            $message->setReplyToMessageId($replyToMessageId);
        }

        $this->entityService->create($message, AuditAction::ChatMessageSent, [
            'project'  => (string) $project->getId(),
            'agent'    => (string) $agent->getId(),
            'author'   => $author->value,
            'exchange' => $message->getExchangeId(),
            'isError'  => $message->isError(),
        ]);

        return $message;
    }

    /**
     * Returns the most recent chat messages for a project/agent pair, up to the given limit.
     *
     * @return ChatMessage[]
     */
    public function getConversation(Project $project, Agent $agent, int $limit = 200): array
    {
        return $this->chatMessageRepository->findConversation($project, $agent, $limit);
    }

    /**
     * Save a human reply to an existing chat message.
     *
     * The reply inherits the exchange ID of the parent message and is persisted immediately.
     *
     * @throws \InvalidArgumentException When the parent message is not found.
     */
    public function reply(Project $project, Agent $agent, string $content, string $replyToMessageId): ChatMessage
    {
        $parentMessage = $this->chatMessageRepository->find($replyToMessageId);
        if ($parentMessage === null) {
            throw new \InvalidArgumentException('Parent message not found');
        }

        return $this->save(
            $project,
            $agent,
            ChatAuthor::Human,
            $content,
            $parentMessage->getExchangeId(),
            false,
            [
                'interaction' => 'chat_reply',
                'connector'   => $agent->getConnector()->value,
            ],
            $parentMessage->getId(),
        );
    }

    /**
     * Edits one human-authored chat message while keeping a minimal edit trace in metadata.
     */
    public function editHumanMessage(Project $project, Agent $agent, string $messageId, string $content): ChatMessage
    {
        $message = $this->chatMessageRepository->find($messageId);
        if (
            $message === null
            || $message->getProject()->getId()->toRfc4122() !== $project->getId()->toRfc4122()
            || $message->getAgent()->getId()->toRfc4122() !== $agent->getId()->toRfc4122()
        ) {
            throw new \InvalidArgumentException('chat.error.message_not_found');
        }

        if ($message->getAuthor() !== ChatAuthor::Human) {
            throw new \InvalidArgumentException('chat.error.message_not_editable');
        }

        $previousContent = trim($message->getContent());
        $nextContent = trim($content);
        if ($previousContent === $nextContent) {
            return $message;
        }

        $message
            ->setContent($nextContent)
            ->setMetadata($this->buildEditedMetadata($message->getMetadata(), $previousContent));

        $this->entityService->flush();

        return $message;
    }

    /**
     * Returns updated metadata with an appended edit history entry.
     *
     * @param array<string, mixed>|null $metadata
     * @return array<string, mixed>
     */
    private function buildEditedMetadata(?array $metadata, string $previousContent): array
    {
        $metadata = $metadata ?? [];
        $history = $metadata['editHistory'] ?? [];
        if (!is_array($history)) {
            $history = [];
        }

        $history[] = [
            'editedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'previousContent' => $previousContent,
        ];

        $metadata['editHistory'] = $history;
        $metadata['editCount'] = count($history);
        $metadata['editedAt'] = $history[array_key_last($history)]['editedAt'];

        return $metadata;
    }
}
