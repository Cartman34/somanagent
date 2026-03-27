<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Agent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Agent>
 */
class AgentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Agent::class);
    }

    /**
     * Returns all active agents that hold the given role slug.
     *
     * @return Agent[]
     */
    public function findActiveByRoleSlug(string $roleSlug): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.role', 'r')
            ->where('r.slug = :slug')
            ->andWhere('a.isActive = true')
            ->setParameter('slug', $roleSlug)
            ->orderBy('a.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
