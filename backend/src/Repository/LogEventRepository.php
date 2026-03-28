<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LogEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
}
