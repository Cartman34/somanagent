<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Agent;
use App\Entity\Team;
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

    /**
     * Returns active agents with the given role slug that belong to the specified team.
     * Used by StoryExecutionService to scope agent selection to the project's team.
     *
     * @param string $roleSlug Role slug to filter by
     * @param Team   $team     Team to scope agent search to
     * @return Agent[]
     */
    public function findActiveByRoleSlugAndTeam(string $roleSlug, Team $team): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.role', 'r')
            ->join('a.teams', 't')
            ->where('r.slug = :slug')
            ->andWhere('a.isActive = true')
            ->andWhere('t = :team')
            ->setParameter('slug', $roleSlug)
            ->setParameter('team', $team)
            ->orderBy('a.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns all active agents whose role includes the given skill slug.
     *
     * @return Agent[]
     */
    public function findActiveBySkillSlug(string $skillSlug): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.role', 'r')
            ->join('r.skills', 's')
            ->where('s.slug = :slug')
            ->andWhere('a.isActive = true')
            ->setParameter('slug', $skillSlug)
            ->orderBy('a.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns active agents in the given team whose role includes the given skill slug.
     *
     * @return Agent[]
     */
    public function findActiveBySkillSlugAndTeam(string $skillSlug, Team $team): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.role', 'r')
            ->join('r.skills', 's')
            ->join('a.teams', 't')
            ->where('s.slug = :slug')
            ->andWhere('a.isActive = true')
            ->andWhere('t = :team')
            ->setParameter('slug', $skillSlug)
            ->setParameter('team', $team)
            ->orderBy('a.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns the number of tasks currently in_progress assigned to this agent.
     * Used to derive the agent's runtime status (working / idle / error).
     *
     * @param Agent $agent The agent to check
     * @return int         Number of active tasks
     */
    public function countInProgressTasks(Agent $agent): int
    {
        return (int) $this->getEntityManager()
            ->createQuery(
                'SELECT COUNT(t.id) FROM App\Entity\Task t
                 WHERE t.assignedAgent = :agent AND t.status = :status'
            )
            ->setParameter('agent', $agent)
            ->setParameter('status', \App\Enum\TaskStatus::InProgress)
            ->getSingleScalarResult();
    }

    /**
     * Returns true if the agent has a recent TaskLog entry with an error action
     * (action contains "error") and no subsequent in_progress task.
     * Used to derive the agent's runtime status.
     *
     * @param Agent $agent The agent to check
     * @return bool        True if the agent is in an error state
     */
    public function hasRecentErrorLog(Agent $agent): bool
    {
        $count = (int) $this->getEntityManager()
            ->createQuery(
                'SELECT COUNT(l.id) FROM App\Entity\TaskLog l
                 JOIN l.task t
                 WHERE t.assignedAgent = :agent AND l.action LIKE :pattern'
            )
            ->setParameter('agent', $agent)
            ->setParameter('pattern', '%error%')
            ->getSingleScalarResult();

        return $count > 0;
    }
}
