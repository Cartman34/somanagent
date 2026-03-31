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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use App\ValueObject\Prompt;

class ChatService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ChatMessageRepository  $chatMessageRepository,
        private readonly AgentPortRegistry      $agentPortRegistry,
        private readonly AgentContextBuilder    $contextBuilder,
        private readonly AuditService           $audit,
    ) {}

    public function sendHuman(Project $project, Agent $agent, string $content): ChatMessage
    {
        $message = $this->save($project, $agent, ChatAuthor::Human, $content);
        $this->em->flush();

        return $message;
    }

    public function sendAgent(Project $project, Agent $agent, string $content): ChatMessage
    {
        $message = $this->save($project, $agent, ChatAuthor::Agent, $content);
        $this->em->flush();

        return $message;
    }

    /**
     * @return array{human: ChatMessage, agent: ChatMessage}
     */
    public function sendAndReceive(Project $project, Agent $agent, string $content): array
    {
        $exchangeId = (string) Uuid::v7();
        $config     = $agent->getAgentConfig();

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

            $response = $this->agentPortRegistry
                ->getFor($agent->getConnector())
                ->sendPrompt($prompt, $config);

            $usage = new TokenUsage(
                agent: $agent,
                model: $config->model,
                inputTokens: $response->inputTokens,
                outputTokens: $response->outputTokens,
                durationMs: (int) $response->durationMs,
            );
            $this->em->persist($usage);

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

        $this->em->flush();

        return ['human' => $human, 'agent' => $agentMessage];
    }

    /**
     * Persist a new ChatMessage and log the corresponding audit event.
     *
     * Does not flush; callers are responsible for calling `$this->em->flush()`.
     *
     * @param array<string, mixed>|null $metadata
     */
    private function save(
        Project $project,
        Agent $agent,
        ChatAuthor $author,
        string $content,
        ?string $exchangeId = null,
        bool $isError = false,
        ?array $metadata = null,
        ?\Symfony\Component\Uid\Uuid $replyToMessageId = null,
    ): ChatMessage
    {
        $message = new ChatMessage($project, $agent, $author, $content, $exchangeId, $isError, $metadata);
        if ($replyToMessageId !== null) {
            $message->setReplyToMessageId($replyToMessageId);
        }
        $this->em->persist($message);
        $this->audit->log(AuditAction::ChatMessageSent, 'ChatMessage', (string) $message->getId(), [
            'project' => (string) $project->getId(),
            'agent'   => (string) $agent->getId(),
            'author'  => $author->value,
            'exchange' => $message->getExchangeId(),
            'isError' => $message->isError(),
        ]);
        return $message;
    }

    /** @return ChatMessage[] */
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

        $message = $this->save(
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
        $this->em->flush();

        return $message;
    }
}
