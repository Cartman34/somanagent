<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AgentAction;
use App\Entity\Workflow;
use App\Entity\WorkflowStep;
use App\Entity\WorkflowStepAction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorkflowStepAction>
 */
final class WorkflowStepActionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowStepAction::class);
    }

    /**
     * @return WorkflowStepAction[]
     */
    public function findByWorkflowAndAction(Workflow $workflow, AgentAction $action): array
    {
        return $this->createQueryBuilder('wsa')
            ->join('wsa.workflowStep', 'ws')
            ->where('ws.workflow = :workflow')
            ->andWhere('wsa.agentAction = :action')
            ->setParameter('workflow', $workflow)
            ->setParameter('action', $action)
            ->orderBy('ws.stepOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findUniqueByWorkflowAndAction(Workflow $workflow, AgentAction $action): ?WorkflowStepAction
    {
        $matches = $this->findByWorkflowAndAction($workflow, $action);

        if (count($matches) !== 1) {
            return null;
        }

        return $matches[0];
    }

    /**
     * @return WorkflowStepAction[]
     */
    public function findCreateWithTicketByWorkflow(Workflow $workflow): array
    {
        return $this->createQueryBuilder('wsa')
            ->join('wsa.workflowStep', 'ws')
            ->where('ws.workflow = :workflow')
            ->andWhere('wsa.createWithTicket = true')
            ->setParameter('workflow', $workflow)
            ->orderBy('ws.stepOrder', 'ASC')
            ->addOrderBy('wsa.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return WorkflowStepAction[]
     */
    public function findByWorkflowStep(WorkflowStep $workflowStep): array
    {
        return $this->createQueryBuilder('wsa')
            ->where('wsa.workflowStep = :workflowStep')
            ->setParameter('workflowStep', $workflowStep)
            ->orderBy('wsa.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
