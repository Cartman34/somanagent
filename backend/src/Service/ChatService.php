<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Agent;
use App\Entity\ChatMessage;
use App\Entity\Project;
use App\Enum\AuditAction;
use App\Enum\ChatAuthor;
use App\Repository\ChatMessageRepository;
use Doctrine\ORM\EntityManagerInterface;

class ChatService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ChatMessageRepository  $chatMessageRepository,
        private readonly AuditService           $audit,
    ) {}

    public function sendHuman(Project $project, Agent $agent, string $content): ChatMessage
    {
        return $this->save($project, $agent, ChatAuthor::Human, $content);
    }

    public function sendAgent(Project $project, Agent $agent, string $content): ChatMessage
    {
        return $this->save($project, $agent, ChatAuthor::Agent, $content);
    }

    private function save(Project $project, Agent $agent, ChatAuthor $author, string $content): ChatMessage
    {
        $message = new ChatMessage($project, $agent, $author, $content);
        $this->em->persist($message);
        $this->em->flush();
        $this->audit->log(AuditAction::ChatMessageSent, 'ChatMessage', (string) $message->getId(), [
            'project' => (string) $project->getId(),
            'agent'   => (string) $agent->getId(),
            'author'  => $author->value,
        ]);
        return $message;
    }

    /** @return ChatMessage[] */
    public function getConversation(Project $project, Agent $agent, int $limit = 200): array
    {
        return $this->chatMessageRepository->findConversation($project, $agent, $limit);
    }
}
