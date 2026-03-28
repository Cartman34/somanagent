<?php

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
            $prompt = Prompt::create('', $content, [
                'project'     => $project->getName(),
                'agent'       => $agent->getName(),
                'interaction' => 'project_chat',
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

    private function save(
        Project $project,
        Agent $agent,
        ChatAuthor $author,
        string $content,
        ?string $exchangeId = null,
        bool $isError = false,
        ?array $metadata = null,
    ): ChatMessage
    {
        $message = new ChatMessage($project, $agent, $author, $content, $exchangeId, $isError, $metadata);
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
}
