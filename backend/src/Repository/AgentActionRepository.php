<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Repository;

use Sowapps\SoManAgent\Entity\AgentAction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AgentAction>
 */
final class AgentActionRepository extends ServiceEntityRepository
{
    /**
     * Registers AgentAction as the managed entity class.
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AgentAction::class);
    }

    /**
     * Returns the active action matching the given key, or null if none found.
     */
    public function findOneByKey(string $key): ?AgentAction
    {
        return $this->findOneBy(['key' => $key]);
    }

    /**
     * Returns the oldest active action assigned to the given role slug, or null if none found.
     */
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

    /**
     * Returns the oldest active action matching the optional role and skill slugs, or null if none found.
     */
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
