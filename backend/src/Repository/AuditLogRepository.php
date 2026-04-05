<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AuditLog;
use App\Entity\Project;
use App\Entity\TicketTask;
use App\Enum\AuditAction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditLog>
 */
class AuditLogRepository extends ServiceEntityRepository
{
    /**
     * Binds the repository to Doctrine's manager registry.
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    /**
     * Returns audit log entries scoped to a project: project-level events and ticket/task events.
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
     * Returns whether a task was already completed at least once in its audit trail.
     */
    public function hasTaskCompletionHistory(TicketTask $ticketTask): bool
    {
        $logs = $this->createQueryBuilder('al')
            ->select('al')
            ->where('al.entityType = :entityType')
            ->andWhere('al.entityId = :entityId')
            ->andWhere('al.action IN (:actions)')
            ->setParameter('entityType', 'TicketTask')
            ->setParameter('entityId', (string) $ticketTask->getId())
            ->setParameter('actions', [
                AuditAction::TaskValidated,
                AuditAction::TaskStatusChanged,
            ])
            ->getQuery()
            ->getResult();

        foreach ($logs as $log) {
            if (!$log instanceof AuditLog) {
                continue;
            }

            if ($log->getAction() === AuditAction::TaskValidated) {
                return true;
            }

            if ($log->getAction() === AuditAction::TaskStatusChanged && ($log->getData()['to'] ?? null) === 'done') {
                return true;
            }
        }

        return false;
    }

    /**
     * Builds a QueryBuilder scoped to project-level and ticket/task-level audit entries.
     * Uses a two-step approach to avoid PostgreSQL uuid vs varchar type mismatch in subqueries.
     */
    private function buildProjectQuery(Project $project): QueryBuilder
    {
        $ticketIds = $this->getEntityManager()
            ->createQuery('SELECT t.id FROM App\Entity\Ticket t WHERE t.project = :project')
            ->setParameter('project', $project)
            ->getSingleColumnResult();

        $ticketTaskIds = $this->getEntityManager()
            ->createQuery('SELECT tt.id FROM App\Entity\TicketTask tt JOIN tt.ticket t WHERE t.project = :project')
            ->setParameter('project', $project)
            ->getSingleColumnResult();

        $ticketIdStrings = array_map(fn($id) => (string) $id, $ticketIds);
        $ticketTaskIdStrings = array_map(fn($id) => (string) $id, $ticketTaskIds);

        $projectId = (string) $project->getId();

        $qb = $this->createQueryBuilder('al')
            ->where('al.entityType = :typeProject AND al.entityId = :projectId')
            ->setParameter('typeProject', 'Project')
            ->setParameter('projectId', $projectId);

        if ($ticketIdStrings !== []) {
            $qb->orWhere('al.entityType = :typeTicket AND al.entityId IN (:ticketIds)')
                ->setParameter('typeTicket', 'Ticket')
                ->setParameter('ticketIds', $ticketIdStrings);
        }

        if ($ticketTaskIdStrings !== []) {
            $qb->orWhere('al.entityType = :typeTicketTask AND al.entityId IN (:ticketTaskIds)')
                ->setParameter('typeTicketTask', 'TicketTask')
                ->setParameter('ticketTaskIds', $ticketTaskIdStrings);
        }

        return $qb;
    }
}
