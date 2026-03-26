<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Task;
use App\Entity\TaskLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TaskLog>
 */
class TaskLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaskLog::class);
    }

    /** @return TaskLog[] */
    public function findByTask(Task $task): array
    {
        return $this->findBy(['task' => $task], ['createdAt' => 'ASC']);
    }
}
