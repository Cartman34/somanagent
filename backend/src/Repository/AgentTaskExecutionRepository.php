<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AgentTaskExecution;
use App\Entity\TicketTask;
use App\Enum\TaskExecutionStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AgentTaskExecution>
 */
final class AgentTaskExecutionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AgentTaskExecution::class);
    }

    /** @return AgentTaskExecution[] */
    public function findByTicketTask(TicketTask $ticketTask): array
    {
        return $this->createQueryBuilder('e')
            ->innerJoin('e.ticketTasks', 'tt')
            ->andWhere('tt = :ticketTask')
            ->setParameter('ticketTask', $ticketTask)
            ->orderBy('e.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findActiveByTicketTask(TicketTask $ticketTask): ?AgentTaskExecution
    {
        return $this->createQueryBuilder('e')
            ->innerJoin('e.ticketTasks', 'tt')
            ->andWhere('tt = :ticketTask')
            ->andWhere('e.status IN (:statuses)')
            ->setParameter('ticketTask', $ticketTask)
            ->setParameter('statuses', [
                TaskExecutionStatus::Pending,
                TaskExecutionStatus::Running,
                TaskExecutionStatus::Retrying,
            ])
            ->orderBy('e.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
