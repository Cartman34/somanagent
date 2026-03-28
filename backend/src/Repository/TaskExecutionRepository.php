<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Task;
use App\Entity\TaskExecution;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<TaskExecution>
 */
final class TaskExecutionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaskExecution::class);
    }

    /**
     * Returns task executions ordered from newest to oldest for ticket display.
     *
     * @return TaskExecution[]
     */
    public function findByTask(Task $task): array
    {
        return $this->createQueryBuilder('execution')
            ->leftJoin('execution.attempts', 'attempt')
            ->addSelect('attempt')
            ->andWhere('execution.task = :task')
            ->setParameter('task', $task)
            ->orderBy('execution.createdAt', 'DESC')
            ->addOrderBy('attempt.attemptNumber', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findById(string $id): ?TaskExecution
    {
        return $this->find(Uuid::fromString($id));
    }
}
