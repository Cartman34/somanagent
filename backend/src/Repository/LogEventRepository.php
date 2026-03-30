<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LogEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LogEvent>
 */
class LogEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LogEvent::class);
    }

    /**
     * @return LogEvent[]
     */
    public function findByOccurrenceSignature(string $category, string $level, string $fingerprint, int $limit = 100): array
    {
        return $this->createQueryBuilder('le')
            ->andWhere('le.category = :category')
            ->andWhere('le.level = :level')
            ->andWhere('le.fingerprint = :fingerprint')
            ->setParameter('category', $category)
            ->setParameter('level', $level)
            ->setParameter('fingerprint', $fingerprint)
            ->orderBy('le.occurredAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param array{
     *   source?: string|null,
     *   category?: string|null,
     *   level?: string|null,
     *   projectId?: string|null,
     *   taskId?: string|null,
     *   agentId?: string|null,
     *   from?: \DateTimeImmutable|null,
     *   to?: \DateTimeImmutable|null
     * } $filters
     *
     * @return LogEvent[]
     */
    public function findFiltered(array $filters, int $limit, int $offset): array
    {
        return $this->buildFilteredQuery($filters)
            ->orderBy('le.occurredAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param array{
     *   source?: string|null,
     *   category?: string|null,
     *   level?: string|null,
     *   projectId?: string|null,
     *   taskId?: string|null,
     *   agentId?: string|null,
     *   from?: \DateTimeImmutable|null,
     *   to?: \DateTimeImmutable|null
     * } $filters
     */
    public function countFiltered(array $filters): int
    {
        return (int) $this->buildFilteredQuery($filters)
            ->select('COUNT(le.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param array{
     *   source?: string|null,
     *   category?: string|null,
     *   level?: string|null,
     *   projectId?: string|null,
     *   taskId?: string|null,
     *   agentId?: string|null,
     *   from?: \DateTimeImmutable|null,
     *   to?: \DateTimeImmutable|null
     * } $filters
     */
    private function buildFilteredQuery(array $filters): QueryBuilder
    {
        $qb = $this->createQueryBuilder('le');

        if (($filters['source'] ?? null) !== null) {
            $qb->andWhere('le.source = :source')->setParameter('source', $filters['source']);
        }
        if (($filters['category'] ?? null) !== null) {
            $qb->andWhere('le.category = :category')->setParameter('category', $filters['category']);
        }
        if (($filters['level'] ?? null) !== null) {
            $qb->andWhere('le.level = :level')->setParameter('level', $filters['level']);
        }
        if (($filters['projectId'] ?? null) !== null) {
            $qb->andWhere('le.projectId = :projectId')->setParameter('projectId', $filters['projectId']);
        }
        if (($filters['taskId'] ?? null) !== null) {
            $qb->andWhere('le.taskId = :taskId')->setParameter('taskId', $filters['taskId']);
        }
        if (($filters['agentId'] ?? null) !== null) {
            $qb->andWhere('le.agentId = :agentId')->setParameter('agentId', $filters['agentId']);
        }
        if (($filters['from'] ?? null) instanceof \DateTimeImmutable) {
            $qb->andWhere('le.occurredAt >= :from')->setParameter('from', $filters['from']);
        }
        if (($filters['to'] ?? null) instanceof \DateTimeImmutable) {
            $qb->andWhere('le.occurredAt <= :to')->setParameter('to', $filters['to']);
        }

        return $qb;
    }
}
