<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Agent;
use App\Entity\ChatMessage;
use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChatMessage>
 */
class ChatMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatMessage::class);
    }

    /** @return ChatMessage[] */
    public function findConversation(Project $project, Agent $agent, int $limit = 200): array
    {
        return $this->findBy(
            ['project' => $project, 'agent' => $agent],
            ['createdAt' => 'ASC'],
            $limit,
        );
    }
}
