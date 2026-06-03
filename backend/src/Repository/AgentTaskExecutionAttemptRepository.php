<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Repository;

use Sowapps\SoManAgent\Entity\AgentTaskExecution;
use Sowapps\SoManAgent\Entity\AgentTaskExecutionAttempt;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AgentTaskExecutionAttempt>
 */
final class AgentTaskExecutionAttemptRepository extends ServiceEntityRepository
{
    /**
     * Registers AgentTaskExecutionAttempt as the managed entity class.
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AgentTaskExecutionAttempt::class);
    }

    /**
     * Returns the attempt matching the given execution and attempt number, or null if none found.
     */
    public function findOneByExecutionAndAttemptNumber(AgentTaskExecution $execution, int $attemptNumber): ?AgentTaskExecutionAttempt
    {
        return $this->findOneBy([
            'execution' => $execution,
            'attemptNumber' => $attemptNumber,
        ]);
    }
}
