<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

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
    private const RUNTIME_ERROR_ACTIONS = [
        'execution_error',
        'planning_parse_error',
    ];

    private const RUNTIME_RECOVERY_ACTIONS = [
        'agent_response',
        'planning_completed',
        'planning_replaced',
        'product_owner_completed',
        'validated',
        'status_changed',
        'rejected',
        'assigned',
    ];

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
                'SELECT COUNT(t.id) FROM App\Entity\TicketTask t
                 WHERE t.assignedAgent = :agent AND t.status = :status'
            )
            ->setParameter('agent', $agent)
            ->setParameter('status', \App\Enum\TaskStatus::InProgress)
            ->getSingleScalarResult();
    }

    /**
     * Returns the latest execution-related runtime signal for the given agent.
     *
     * Error signals keep the agent in `error` only until a later recovery signal
     * is recorded on one of its assigned tasks.
     *
     * @return array{action: string, createdAt: \DateTimeImmutable}|null
     */
    public function findLatestRuntimeSignal(Agent $agent): ?array
    {
        $actions = array_merge(self::RUNTIME_ERROR_ACTIONS, self::RUNTIME_RECOVERY_ACTIONS);

        /** @var array{action: string, createdAt: \DateTimeImmutable}|null $signal */
        $signal = $this->getEntityManager()
            ->createQuery(
                'SELECT l.action AS action, l.createdAt AS createdAt
                 FROM App\Entity\TicketLog l
                 LEFT JOIN l.ticketTask t
                 LEFT JOIN l.ticket ticket
                 WHERE (t.assignedAgent = :agent AND l.action IN (:actions))
                    OR (ticket.assignedAgent = :agent AND l.action IN (:actions))
                 ORDER BY l.createdAt DESC'
            )
            ->setParameter('agent', $agent)
            ->setParameter('actions', $actions)
            ->setMaxResults(1)
            ->getOneOrNullResult();

        return $signal;
    }

    /**
     * Returns whether the given runtime signal action should surface the agent as `error`.
     */
    public function isRuntimeErrorAction(string $action): bool
    {
        return in_array($action, self::RUNTIME_ERROR_ACTIONS, true);
    }
}
