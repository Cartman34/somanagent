<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LogOccurrence;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LogOccurrence>
 */
class LogOccurrenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LogOccurrence::class);
    }

    public function findOneByFingerprint(string $category, string $level, string $fingerprint): ?LogOccurrence
    {
        return $this->findOneBy([
            'category' => $category,
            'level' => $level,
            'fingerprint' => $fingerprint,
        ]);
    }

    /**
     * @param array{
     *   source?: string|null,
     *   category?: string|null,
     *   level?: string|null,
     *   projectId?: string|null,
     *   taskId?: string|null,
     *   agentId?: string|null,
     *   status?: string|null,
     *   from?: \DateTimeImmutable|null,
     *   to?: \DateTimeImmutable|null
     * } $filters
     */
    public function findFiltered(array $filters, int $limit, int $offset): array
    {
        return $this->buildFilteredQuery($filters)
            ->orderBy('lo.lastSeenAt', 'DESC')
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
     *   status?: string|null,
     *   from?: \DateTimeImmutable|null,
     *   to?: \DateTimeImmutable|null
     * } $filters
     */
    public function countFiltered(array $filters): int
    {
        return (int) $this->buildFilteredQuery($filters)
            ->select('COUNT(lo.id)')
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
     *   status?: string|null,
     *   from?: \DateTimeImmutable|null,
     *   to?: \DateTimeImmutable|null
     * } $filters
     */
    private function buildFilteredQuery(array $filters): QueryBuilder
    {
        $qb = $this->createQueryBuilder('lo');

        if (($filters['source'] ?? null) !== null) {
            $qb->andWhere('lo.source = :source')->setParameter('source', $filters['source']);
        }
        if (($filters['category'] ?? null) !== null) {
            $qb->andWhere('lo.category = :category')->setParameter('category', $filters['category']);
        }
        if (($filters['level'] ?? null) !== null) {
            $qb->andWhere('lo.level = :level')->setParameter('level', $filters['level']);
        }
        if (($filters['projectId'] ?? null) !== null) {
            $qb->andWhere('lo.projectId = :projectId')->setParameter('projectId', $filters['projectId']);
        }
        if (($filters['taskId'] ?? null) !== null) {
            $qb->andWhere('lo.taskId = :taskId')->setParameter('taskId', $filters['taskId']);
        }
        if (($filters['agentId'] ?? null) !== null) {
            $qb->andWhere('lo.agentId = :agentId')->setParameter('agentId', $filters['agentId']);
        }
        if (($filters['status'] ?? null) !== null) {
            $qb->andWhere('lo.status = :status')->setParameter('status', $filters['status']);
        }
        if (($filters['from'] ?? null) instanceof \DateTimeImmutable) {
            $qb->andWhere('lo.lastSeenAt >= :from')->setParameter('from', $filters['from']);
        }
        if (($filters['to'] ?? null) instanceof \DateTimeImmutable) {
            $qb->andWhere('lo.lastSeenAt <= :to')->setParameter('to', $filters['to']);
        }

        return $qb;
    }
}
