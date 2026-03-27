<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Agent;
use App\Entity\Feature;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\TaskLog;
use App\Enum\AuditAction;
use App\Enum\StoryStatus;
use App\Enum\TaskPriority;
use App\Enum\TaskStatus;
use App\Enum\TaskType;
use App\Repository\AgentRepository;
use App\Repository\FeatureRepository;
use App\Repository\TaskRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class TaskService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TaskRepository         $taskRepository,
        private readonly FeatureRepository      $featureRepository,
        private readonly AgentRepository        $agentRepository,
        private readonly AuditService           $audit,
    ) {}

    public function create(
        Project      $project,
        TaskType     $type,
        string       $title,
        ?string      $description   = null,
        TaskPriority $priority      = TaskPriority::Medium,
        ?string      $featureId     = null,
        ?string      $parentId      = null,
        ?string      $assignedAgentId = null,
    ): Task {
        $task = new Task($project, $type, $title, $description, $priority);

        if ($featureId !== null) {
            $feature = $this->featureRepository->find(Uuid::fromString($featureId));
            if ($feature !== null) {
                $task->setFeature($feature);
            }
        }

        if ($parentId !== null) {
            $parent = $this->taskRepository->find(Uuid::fromString($parentId));
            if ($parent !== null) {
                $task->setParent($parent);
            }
        }

        if ($assignedAgentId !== null) {
            $agent = $this->agentRepository->find(Uuid::fromString($assignedAgentId));
            if ($agent !== null) {
                $task->setAssignedAgent($agent);
            }
        }

        $this->em->persist($task);
        $this->log($task, 'created', "Tâche créée : {$title}");
        $this->em->flush();

        $this->audit->log(AuditAction::TaskCreated, 'Task', (string) $task->getId(), [
            'title'   => $title,
            'type'    => $type->value,
            'project' => (string) $project->getId(),
        ]);
        return $task;
    }

    public function update(
        Task         $task,
        string       $title,
        ?string      $description,
        TaskPriority $priority,
        ?string      $featureId,
        ?string      $assignedAgentId,
    ): Task {
        $task->setTitle($title)->setDescription($description)->setPriority($priority);

        $feature = $featureId ? $this->featureRepository->find(Uuid::fromString($featureId)) : null;
        $task->setFeature($feature);

        $agent = $assignedAgentId ? $this->agentRepository->find(Uuid::fromString($assignedAgentId)) : null;
        if ($agent !== $task->getAssignedAgent()) {
            $task->setAssignedAgent($agent);
            if ($agent !== null) {
                $this->log($task, 'assigned', "Assigné à : {$agent->getName()}");
                $this->audit->log(AuditAction::TaskAssigned, 'Task', (string) $task->getId(), ['agent' => (string) $agent->getId()]);
            }
        }

        $this->em->flush();
        $this->audit->log(AuditAction::TaskUpdated, 'Task', (string) $task->getId());
        return $task;
    }

    public function changeStatus(Task $task, TaskStatus $status): Task
    {
        $old = $task->getStatus();
        $task->setStatus($status);

        // Passage à done → progression 100%
        if ($status === TaskStatus::Done) {
            $task->setProgress(100);
        }

        $this->log($task, 'status_changed', "Statut : {$old->value} → {$status->value}");
        $this->em->flush();
        $this->audit->log(AuditAction::TaskStatusChanged, 'Task', (string) $task->getId(), [
            'from' => $old->value,
            'to'   => $status->value,
        ]);
        return $task;
    }

    public function updateProgress(Task $task, int $progress): Task
    {
        $task->setProgress($progress);
        $this->log($task, 'progress_updated', "Progression : {$progress}%");
        $this->em->flush();
        $this->audit->log(AuditAction::TaskProgressUpdated, 'Task', (string) $task->getId(), ['progress' => $progress]);
        return $task;
    }

    public function reprioritize(Task $task, TaskPriority $priority): Task
    {
        $old = $task->getPriority();
        $task->setPriority($priority);
        $this->log($task, 'reprioritized', "Priorité : {$old->value} → {$priority->value}");
        $this->em->flush();
        $this->audit->log(AuditAction::TaskReprioritized, 'Task', (string) $task->getId(), [
            'from' => $old->value,
            'to'   => $priority->value,
        ]);
        return $task;
    }

    /**
     * Demande de validation manuelle par l'humain.
     * Passe le statut à "review" et logge l'événement.
     */
    public function requestValidation(Task $task, ?string $comment = null): Task
    {
        $task->setStatus(TaskStatus::Review);
        $this->log($task, 'validation_asked', $comment ?? 'Validation manuelle demandée.');
        $this->em->flush();
        $this->audit->log(AuditAction::TaskValidationAsked, 'Task', (string) $task->getId());
        return $task;
    }

    public function validate(Task $task): Task
    {
        $task->setStatus(TaskStatus::Done)->setProgress(100);
        $this->log($task, 'validated', 'Tâche validée manuellement.');
        $this->em->flush();
        $this->audit->log(AuditAction::TaskValidated, 'Task', (string) $task->getId());
        return $task;
    }

    public function reject(Task $task, ?string $reason = null): Task
    {
        $task->setStatus(TaskStatus::InProgress);
        $this->log($task, 'rejected', $reason ?? 'Tâche rejetée, retour en cours.');
        $this->em->flush();
        $this->audit->log(AuditAction::TaskRejected, 'Task', (string) $task->getId(), ['reason' => $reason]);
        return $task;
    }

    /**
     * @throws \LogicException si la transition n'est pas autorisée
     */
    public function transitionStory(Task $task, StoryStatus $next): Task
    {
        $task->transitionStoryTo($next);
        $this->log($task, 'story_transition', "Statut story → {$next->value}");
        $this->em->flush();
        $this->audit->log(AuditAction::TaskUpdated, 'Task', (string) $task->getId(), ['story_status' => $next->value]);
        return $task;
    }

    public function delete(Task $task): void
    {
        $id = (string) $task->getId();
        $this->em->remove($task);
        $this->em->flush();
        $this->audit->log(AuditAction::TaskDeleted, 'Task', $id);
    }

    /** @return Task[] */
    public function findByProject(Project $project): array
    {
        return $this->taskRepository->findRootByProject($project);
    }

    /** @return Task[] */
    public function findChildren(Task $task): array
    {
        return $this->taskRepository->findChildren($task);
    }

    /** @return Task[] */
    public function findByFeature(Feature $feature): array
    {
        return $this->taskRepository->findByFeature($feature);
    }

    /** @return Task[] */
    public function findByAgent(Agent $agent): array
    {
        return $this->taskRepository->findByAgent($agent);
    }

    public function findById(string $id): ?Task
    {
        return $this->taskRepository->find(Uuid::fromString($id));
    }

    private function log(Task $task, string $action, ?string $content): void
    {
        $log = new TaskLog($task, $action, $content);
        $this->em->persist($log);
    }
}
