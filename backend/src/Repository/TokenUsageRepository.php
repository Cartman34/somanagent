<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Agent;
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
}
