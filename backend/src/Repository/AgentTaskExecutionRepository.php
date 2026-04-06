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
    /**
     * Binds the repository to Doctrine's manager registry.
     */
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

    /**
     * Returns the newest active execution for one task, if any.
     */
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

    /**
     * Returns whether at least one execution is already linked to this task.
     */
    public function hasAnyByTicketTask(TicketTask $ticketTask): bool
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->innerJoin('e.ticketTasks', 'tt')
            ->andWhere('tt = :ticketTask')
            ->setParameter('ticketTask', $ticketTask)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * Returns all executions where the given agent was requested or effective, ordered by creation date descending.
     *
     * @param string $agentId UUID of the agent
     * @param int    $limit   Maximum number of results
     *
     * @return AgentTaskExecution[]
     */
    public function findByAgent(string $agentId, int $limit = 50): array
    {
        $executionIds = array_column(
            $this->createQueryBuilder('e')
                ->select('e.id')
                ->leftJoin('e.requestedAgent', 'ra')
                ->leftJoin('e.effectiveAgent', 'ea')
                ->andWhere('ra.id = :agentId OR ea.id = :agentId')
                ->setParameter('agentId', $agentId)
                ->orderBy('e.createdAt', 'DESC')
                ->setMaxResults($limit)
                ->getQuery()
                ->getScalarResult(),
            'id',
        );

        if ($executionIds === []) {
            return [];
        }

        return $this->createQueryBuilder('e')
            ->distinct()
            ->leftJoin('e.requestedAgent', 'ra')
            ->addSelect('ra')
            ->leftJoin('e.effectiveAgent', 'ea')
            ->addSelect('ea')
            ->leftJoin('e.attempts', 'attempt')
            ->addSelect('attempt')
            ->leftJoin('attempt.agent', 'attemptAgent')
            ->addSelect('attemptAgent')
            ->leftJoin('e.ticketTasks', 'tt')
            ->addSelect('tt')
            ->leftJoin('tt.ticket', 'ticket')
            ->addSelect('ticket')
            ->andWhere('e.id IN (:executionIds)')
            ->setParameter('executionIds', $executionIds)
            ->orderBy('e.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
