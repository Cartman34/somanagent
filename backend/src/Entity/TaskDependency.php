<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TaskDependencyRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Représente une dépendance entre deux tâches dans le DAG d'exécution.
 * Une tâche ne peut démarrer que si toutes ses dépendances sont Done.
 */
#[ORM\Entity(repositoryClass: TaskDependencyRepository::class)]
#[ORM\Table(name: 'task_dependency')]
#[ORM\UniqueConstraint(columns: ['task_id', 'depends_on_id'])]
class TaskDependency
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Task::class)]
    #[ORM\JoinColumn(name: 'task_id', nullable: false, onDelete: 'CASCADE')]
    private Task $task;

    #[ORM\ManyToOne(targetEntity: Task::class)]
    #[ORM\JoinColumn(name: 'depends_on_id', nullable: false, onDelete: 'CASCADE')]
    private Task $dependsOn;

    public function __construct(Task $task, Task $dependsOn)
    {
        $this->id        = Uuid::v7();
        $this->task      = $task;
        $this->dependsOn = $dependsOn;
    }

    public function getId(): Uuid      { return $this->id; }
    public function getTask(): Task    { return $this->task; }
    public function getDependsOn(): Task { return $this->dependsOn; }
}
