<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LogOccurrence;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
}
