<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Repository;

use Sowapps\SoManAgent\Entity\Project;
use Sowapps\SoManAgent\Entity\Agent;
use Sowapps\SoManAgent\Entity\ChatMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChatMessage>
 */
class ChatMessageRepository extends ServiceEntityRepository
{
    /**
     * @param ManagerRegistry $registry
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatMessage::class);
    }

    /**
     * @return ChatMessage[]
     */
    public function findConversation(Project $project, Agent $agent, int $limit = 200): array
    {
        return $this->findBy(
            ['project' => $project, 'agent' => $agent],
            ['createdAt' => 'ASC'],
            $limit,
        );
    }
}
