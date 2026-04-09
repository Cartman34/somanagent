<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Ticket;
use App\Entity\TicketTask;
use App\Entity\TicketLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TicketLog>
 */
final class TicketLogRepository extends ServiceEntityRepository
{
    /**
     * Initialises the repository for ticket log entities.
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TicketLog::class);
    }

    /** @return TicketLog[] */
    public function findByTicket(Ticket $ticket): array
    {
        return $this->findBy(['ticket' => $ticket], ['createdAt' => 'ASC']);
    }

    /** @return TicketLog[] */
    public function findByTicketTask(TicketTask $ticketTask): array
    {
        return $this->findBy(['ticketTask' => $ticketTask], ['createdAt' => 'ASC']);
    }

    /**
     * Returns one ticket log by ticket scope and identifier.
     */
    public function findOneByTicketAndId(Ticket $ticket, string $id): ?TicketLog
    {
        return $this->findOneBy(['ticket' => $ticket, 'id' => \Symfony\Component\Uid\Uuid::fromString($id)]);
    }

    /**
     * Returns whether a log already has direct replies within the same ticket thread.
     */
    public function hasReplies(Ticket $ticket, TicketLog $log, ?TicketLog $excluding = null): bool
    {
        $qb = $this->createQueryBuilder('log')
            ->select('COUNT(log.id)')
            ->andWhere('log.ticket = :ticket')
            ->andWhere('log.replyToLogId = :replyToLogId')
            ->setParameter('ticket', $ticket)
            ->setParameter('replyToLogId', $log->getId(), 'uuid');

        if ($excluding !== null) {
            $qb
                ->andWhere('log.id != :excludingId')
                ->setParameter('excludingId', $excluding->getId(), 'uuid');
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /** @return TicketLog[] */
    public function findAgentQuestionsByTicket(Ticket $ticket): array
    {
        return $this->findBy(['ticket' => $ticket, 'action' => 'agent_question'], ['createdAt' => 'ASC']);
    }
}
