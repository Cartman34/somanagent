<?php

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

    public function findOneByTicketAndId(Ticket $ticket, string $id): ?TicketLog
    {
        return $this->findOneBy(['ticket' => $ticket, 'id' => \Symfony\Component\Uid\Uuid::fromString($id)]);
    }
}
