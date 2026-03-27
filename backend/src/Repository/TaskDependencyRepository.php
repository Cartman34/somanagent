<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Task;
use App\Entity\TaskDependency;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TaskDependency>
 */
class TaskDependencyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaskDependency::class);
    }

    /**
     * Retourne les tâches dont toutes les dépendances sont Done — prêtes à démarrer.
     *
     * @param Task[] $tasks
     * @return Task[]
     */
    public function findReadyTasks(array $tasks): array
    {
        if (empty($tasks)) {
            return [];
        }

        $ready = [];
        foreach ($tasks as $task) {
            $deps = $this->findBy(['task' => $task]);
            $allDone = true;
            foreach ($deps as $dep) {
                if (!$dep->getDependsOn()->getStatus()->isDone()) {
                    $allDone = false;
                    break;
                }
            }
            if ($allDone) {
                $ready[] = $task;
            }
        }
        return $ready;
    }
}
