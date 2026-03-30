<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Workflow;
use App\Entity\WorkflowStep;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorkflowStep>
 */
class WorkflowStepRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowStep::class);
    }

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
