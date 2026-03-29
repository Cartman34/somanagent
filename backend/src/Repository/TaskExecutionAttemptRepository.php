<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TaskExecution;
use App\Entity\TaskExecutionAttempt;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TaskExecutionAttempt>
 */
final class TaskExecutionAttemptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaskExecutionAttempt::class);
    }

    public function findOneByExecutionAndAttemptNumber(TaskExecution $execution, int $attemptNumber): ?TaskExecutionAttempt
    {
        return $this->findOneBy([
            'execution' => $execution,
            'attemptNumber' => $attemptNumber,
        ]);
    }
}
