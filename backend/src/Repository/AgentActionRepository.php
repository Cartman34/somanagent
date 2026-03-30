<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AgentAction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AgentAction>
 */
final class AgentActionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AgentAction::class);
    }

    public function findOneByKey(string $key): ?AgentAction
    {
        return $this->findOneBy(['key' => $key]);
    }

    public function findOneActiveByRoleSlug(string $roleSlug): ?AgentAction
    {
        return $this->createQueryBuilder('aa')
            ->join('aa.role', 'r')
            ->andWhere('aa.isActive = true')
            ->andWhere('r.slug = :roleSlug')
            ->setParameter('roleSlug', $roleSlug)
            ->orderBy('aa.createdAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneActiveByRoleAndSkill(?string $roleSlug, ?string $skillSlug): ?AgentAction
    {
        $qb = $this->createQueryBuilder('aa')
            ->leftJoin('aa.role', 'r')
            ->leftJoin('aa.skill', 's')
            ->andWhere('aa.isActive = true');

        if ($roleSlug !== null) {
            $qb->andWhere('r.slug = :roleSlug')->setParameter('roleSlug', $roleSlug);
        }

        if ($skillSlug !== null) {
            $qb->andWhere('s.slug = :skillSlug')->setParameter('skillSlug', $skillSlug);
        }

        return $qb
            ->orderBy('aa.createdAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
