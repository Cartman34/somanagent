<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AgentAction;
use App\Entity\Agent;
use App\Entity\Ticket;
use App\Entity\TicketTask;
use App\Entity\WorkflowStep;
use App\Enum\TaskStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TicketTask>
 */
final class TicketTaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TicketTask::class);
    }

    /** @return TicketTask[] */
    public function findByTicket(Ticket $ticket): array
    {
        return $this->findBy(['ticket' => $ticket], ['createdAt' => 'ASC']);
    }

    /** @return TicketTask[] */
    public function findRootsByTicket(Ticket $ticket): array
    {
        return $this->createQueryBuilder('tt')
            ->andWhere('tt.ticket = :ticket')
            ->andWhere('tt.parent IS NULL')
            ->setParameter('ticket', $ticket)
            ->orderBy('tt.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return TicketTask[] */
    public function findChildren(TicketTask $ticketTask): array
    {
        return $this->findBy(['parent' => $ticketTask], ['createdAt' => 'ASC']);
    }

    /**
     * @param TicketTask[] $parents
     * @return array<string, TicketTask[]>
     */
    public function findChildrenGroupedByParent(array $parents): array
    {
        if ($parents === []) {
            return [];
        }

        $children = $this->createQueryBuilder('tt')
            ->andWhere('tt.parent IN (:parents)')
            ->setParameter('parents', $parents)
            ->orderBy('tt.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($children as $child) {
            $parent = $child->getParent();
            if ($parent === null) {
                continue;
            }

            $grouped[$parent->getId()->toRfc4122()][] = $child;
        }

        return $grouped;
    }

    public function findOneLatestByTicketAndWorkflowStepAndAction(Ticket $ticket, ?WorkflowStep $workflowStep, AgentAction $agentAction): ?TicketTask
    {
        $qb = $this->createQueryBuilder('tt')
            ->andWhere('tt.ticket = :ticket')
            ->andWhere('tt.agentAction = :agentAction')
            ->setParameter('ticket', $ticket)
            ->setParameter('agentAction', $agentAction)
            ->orderBy('tt.createdAt', 'DESC')
            ->setMaxResults(1);

        if ($workflowStep === null) {
            $qb->andWhere('tt.workflowStep IS NULL');
        } else {
            $qb->andWhere('tt.workflowStep = :workflowStep')->setParameter('workflowStep', $workflowStep);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /** @return TicketTask[] */
    public function findByAssignedAgent(Agent $agent): array
    {
        return $this->findBy(['assignedAgent' => $agent], ['updatedAt' => 'DESC']);
    }

    /** @return TicketTask[] */
    public function findByStatus(TaskStatus $status): array
    {
        return $this->findBy(['status' => $status], ['updatedAt' => 'ASC']);
    }
}
