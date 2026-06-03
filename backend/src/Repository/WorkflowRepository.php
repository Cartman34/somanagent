<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Repository;

use Sowapps\SoManAgent\Entity\Workflow;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Workflow>
 */
class WorkflowRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Workflow::class);
    }

    /**
     * Returns whether at least one persisted record already references a step from the workflow.
     */
    public function hasUsage(Workflow $workflow): bool
    {
        $result = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('1')
            ->from('Sowapps\SoManAgent\Entity\WorkflowStep', 'ws')
            ->leftJoin('Sowapps\SoManAgent\Entity\Ticket', 't', 'WITH', 't.workflowStep = ws')
            ->leftJoin('Sowapps\SoManAgent\Entity\TicketTask', 'tt', 'WITH', 'tt.workflowStep = ws')
            ->leftJoin('Sowapps\SoManAgent\Entity\TokenUsage', 'tu', 'WITH', 'tu.workflowStep = ws')
            ->where('ws.workflow = :workflow')
            ->andWhere('t.id IS NOT NULL OR tt.id IS NOT NULL OR tu.id IS NOT NULL')
            ->setParameter('workflow', $workflow)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result !== null;
    }

    /**
     * Returns whether at least one project currently references the workflow.
     */
    public function hasProjectReferences(Workflow $workflow): bool
    {
        $result = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('1')
            ->from('Sowapps\SoManAgent\Entity\Project', 'p')
            ->where('p.workflow = :workflow')
            ->setParameter('workflow', $workflow)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result !== null;
    }
}
