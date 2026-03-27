<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AuditLog;
use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditLog>
 */
class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    /**
     * Returns audit log entries scoped to a project: project-level events and task events.
     *
     * @return AuditLog[]
     */
    public function findByProject(Project $project, int $limit = 25, int $offset = 0): array
    {
        return $this->buildProjectQuery($project)
            ->orderBy('al.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    /**
     * Counts audit log entries scoped to a project.
     */
    public function countByProject(Project $project): int
    {
        return (int) $this->buildProjectQuery($project)
            ->select('COUNT(al.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Builds a QueryBuilder scoped to project-level and task-level audit entries.
     * Uses a two-step approach to avoid PostgreSQL uuid vs varchar type mismatch in subqueries.
     */
    private function buildProjectQuery(Project $project): QueryBuilder
    {
        $taskIds = $this->getEntityManager()
            ->createQuery('SELECT t.id FROM App\Entity\Task t WHERE t.project = :project')
            ->setParameter('project', $project)
            ->getSingleColumnResult();

        $taskIdStrings = array_map(fn($id) => (string) $id, $taskIds);

        $projectId = (string) $project->getId();

        $qb = $this->createQueryBuilder('al')
            ->where('al.entityType = :typeProject AND al.entityId = :projectId')
            ->setParameter('typeProject', 'Project')
            ->setParameter('projectId', $projectId);

        if (!empty($taskIdStrings)) {
            $qb->orWhere('al.entityType = :typeTask AND al.entityId IN (:taskIds)')
                ->setParameter('typeTask', 'Task')
                ->setParameter('taskIds', $taskIdStrings);
        }

        return $qb;
    }
}
