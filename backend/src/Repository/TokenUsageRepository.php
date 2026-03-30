<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Agent;
use App\Entity\Project;
use App\Entity\Ticket;
use App\Entity\TicketTask;
use App\Entity\TokenUsage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TokenUsage>
 */
class TokenUsageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TokenUsage::class);
    }

    /**
     * Totaux de tokens par agent sur une période.
     * Retourne ['agentId' => string, 'agentName' => string, 'totalInput' => int, 'totalOutput' => int][]
     */
    public function sumByAgent(?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null): array
    {
        $qb = $this->createQueryBuilder('tu')
            ->select('IDENTITY(tu.agent) as agentId, SUM(tu.inputTokens) as totalInput, SUM(tu.outputTokens) as totalOutput, COUNT(tu.id) as calls')
            ->groupBy('tu.agent');

        if ($from !== null) {
            $qb->andWhere('tu.createdAt >= :from')->setParameter('from', $from);
        }
        if ($to !== null) {
            $qb->andWhere('tu.createdAt <= :to')->setParameter('to', $to);
        }

        return $qb->getQuery()->getArrayResult();
    }

    /** @return TokenUsage[] */
    public function findByAgent(Agent $agent, int $limit = 100): array
    {
        return $this->findBy(['agent' => $agent], ['createdAt' => 'DESC'], $limit);
    }

    /**
     * Returns token usage entries for all tasks belonging to the given project.
     *
     * @return TokenUsage[]
     */
    public function findByProject(Project $project, int $limit = 100): array
    {
        return $this->createQueryBuilder('tu')
            ->leftJoin('tu.ticket', 'ticket')
            ->leftJoin('tu.ticketTask', 'ticketTask')
            ->leftJoin('ticketTask.ticket', 'ticketTaskTicket')
            ->where('ticket.project = :project OR ticketTaskTicket.project = :project')
            ->setParameter('project', $project)
            ->orderBy('tu.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Aggregates token usage by agent for the given project.
     * Returns ['agentId' => string, 'totalInput' => int, 'totalOutput' => int, 'calls' => int][]
     */
    public function sumByProjectAndAgent(Project $project): array
    {
        return $this->createQueryBuilder('tu')
            ->select('IDENTITY(tu.agent) as agentId, SUM(tu.inputTokens) as totalInput, SUM(tu.outputTokens) as totalOutput, COUNT(tu.id) as calls')
            ->leftJoin('tu.ticket', 'ticket')
            ->leftJoin('tu.ticketTask', 'ticketTask')
            ->leftJoin('ticketTask.ticket', 'ticketTaskTicket')
            ->where('ticket.project = :project OR ticketTaskTicket.project = :project')
            ->setParameter('project', $project)
            ->groupBy('tu.agent')
            ->getQuery()
            ->getArrayResult();
    }

    /** @return TokenUsage[] */
    public function findByTicket(Ticket $ticket, int $limit = 50): array
    {
        return $this->createQueryBuilder('tu')
            ->leftJoin('tu.ticketTask', 'ticketTask')
            ->where('tu.ticket = :ticket OR ticketTask.ticket = :ticket')
            ->setParameter('ticket', $ticket)
            ->orderBy('tu.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return TokenUsage[] */
    public function findByTicketTask(TicketTask $ticketTask, int $limit = 50): array
    {
        return $this->findBy(['ticketTask' => $ticketTask], ['createdAt' => 'DESC'], $limit);
    }
}
