<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AgentTaskExecution;
use App\Entity\AgentTaskExecutionAttempt;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AgentTaskExecutionAttempt>
 */
final class AgentTaskExecutionAttemptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AgentTaskExecutionAttempt::class);
    }

    public function findOneByExecutionAndAttemptNumber(AgentTaskExecution $execution, int $attemptNumber): ?AgentTaskExecutionAttempt
    {
        return $this->findOneBy([
            'execution' => $execution,
            'attemptNumber' => $attemptNumber,
        ]);
    }
}
