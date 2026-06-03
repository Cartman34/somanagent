<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Repository;

use Sowapps\SoManAgent\Entity\Workflow;
use Sowapps\SoManAgent\Entity\WorkflowStep;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorkflowStep>
 */
class WorkflowStepRepository extends ServiceEntityRepository
{
    /**
     * Registers WorkflowStep as the managed entity class.
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowStep::class);
    }

    /**
     * Returns the step matching the given workflow and output key, or null if none found.
     */
    public function findByWorkflowAndKey(Workflow $workflow, string $key): ?WorkflowStep
    {
        return $this->createQueryBuilder('ws')
            ->where('ws.workflow = :workflow')
            ->andWhere('ws.outputKey = :key')
            ->setParameter('workflow', $workflow)
            ->setParameter('key', $key)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Returns the first step of the given workflow ordered by stepOrder, or null if the workflow has no steps.
     */
    public function findFirstByWorkflow(Workflow $workflow): ?WorkflowStep
    {
        return $this->createQueryBuilder('ws')
            ->where('ws.workflow = :workflow')
            ->setParameter('workflow', $workflow)
            ->orderBy('ws.stepOrder', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Returns the next step after the given step (by stepOrder), or null if the given step is the last.
     */
    public function findNextByWorkflowStep(WorkflowStep $workflowStep): ?WorkflowStep
    {
        return $this->createQueryBuilder('ws')
            ->where('ws.workflow = :workflow')
            ->andWhere('ws.stepOrder > :stepOrder')
            ->setParameter('workflow', $workflowStep->getWorkflow())
            ->setParameter('stepOrder', $workflowStep->getStepOrder())
            ->orderBy('ws.stepOrder', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
