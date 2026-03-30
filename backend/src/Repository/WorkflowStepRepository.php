<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Team;
use App\Entity\WorkflowStep;
use App\Enum\StoryStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorkflowStep>
 */
class WorkflowStepRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowStep::class);
    }

    /**
     * Returns the first workflow step configured for a given story status trigger
     * within any workflow belonging to the given team.
     *
     * Used by StoryExecutionService to resolve roleSlug/skillSlug from the workflow
     * instead of using the hardcoded execution map.
     *
     * @param Team        $team   The project's team whose workflows are searched
     * @param StoryStatus $status The story status to match against storyStatusTrigger
     * @return WorkflowStep|null  First matching step, or null if none configured
     */
    public function findByTeamAndStoryStatus(Team $team, StoryStatus $status): ?WorkflowStep
    {
        return $this->createQueryBuilder('ws')
            ->join('ws.workflow', 'w')
            ->where('w.team = :team')
            ->andWhere('ws.storyStatusTrigger = :status')
            ->setParameter('team', $team)
            ->setParameter('status', $status)
            ->orderBy('ws.stepOrder', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Returns the first lifecycle workflow step for the team that uses the given role slug.
     */
    public function findLifecycleStepByTeamAndRoleSlug(Team $team, string $roleSlug): ?WorkflowStep
    {
        return $this->createQueryBuilder('ws')
            ->join('ws.workflow', 'w')
            ->where('w.team = :team')
            ->andWhere('ws.roleSlug = :roleSlug')
            ->andWhere('ws.storyStatusTrigger IS NOT NULL')
            ->setParameter('team', $team)
            ->setParameter('roleSlug', $roleSlug)
            ->orderBy('ws.stepOrder', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
