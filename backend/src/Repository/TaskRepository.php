<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Agent;
use App\Entity\Feature;
use App\Entity\Project;
use App\Entity\Task;
use App\Enum\TaskType;
use App\Enum\TaskStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Task>
 */
class TaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Task::class);
    }

    /** @return Task[] Tâches racines d'un projet (sans parent) */
    public function findRootByProject(Project $project): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.project = :project')
            ->andWhere('t.parent IS NULL')
            ->setParameter('project', $project)
            ->orderBy('t.priority', 'DESC')
            ->addOrderBy('t.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return Task[] Sous-tâches d'une tâche */
    public function findChildren(Task $task): array
    {
        return $this->findBy(['parent' => $task], ['createdAt' => 'ASC']);
    }

    /** @return Task[] Tâches d'une feature */
    public function findByFeature(Feature $feature): array
    {
        return $this->findBy(['feature' => $feature, 'parent' => null], ['priority' => 'DESC']);
    }

    /** @return Task[] Tâches assignées à un agent */
    public function findByAgent(Agent $agent): array
    {
        return $this->findBy(['assignedAgent' => $agent], ['updatedAt' => 'DESC']);
    }

    /** @return Task[] Tâches d'un projet avec un statut donné */
    public function findByProjectAndStatus(Project $project, TaskStatus $status): array
    {
        return $this->findBy(['project' => $project, 'status' => $status]);
    }

    /** @return Task[] */
    public function findRecentStories(int $limit = 10): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.type IN (:types)')
            ->andWhere('t.parent IS NULL')
            ->setParameter('types', [TaskType::UserStory, TaskType::Bug])
            ->orderBy('t.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return Task[] */
    public function findStoriesByTitleLike(string $query, int $limit = 10): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.type IN (:types)')
            ->andWhere('t.parent IS NULL')
            ->andWhere('LOWER(t.title) LIKE :query')
            ->setParameter('types', [TaskType::UserStory, TaskType::Bug])
            ->setParameter('query', '%' . mb_strtolower($query) . '%')
            ->orderBy('t.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
