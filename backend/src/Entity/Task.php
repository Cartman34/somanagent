<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TaskPriority;
use App\Enum\TaskStatus;
use App\Enum\TaskType;
use App\Repository\TaskRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TaskRepository::class)]
#[ORM\Table(name: 'task')]
#[ORM\HasLifecycleCallbacks]
class Task
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Project $project;

    /**
     * Feature parente (optionnelle).
     */
    #[ORM\ManyToOne(targetEntity: Feature::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Feature $feature = null;

    /**
     * Tâche parente pour les sous-tâches (auto-référence).
     */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Task $parent = null;

    #[ORM\Column(enumType: TaskType::class)]
    private TaskType $type;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(enumType: TaskStatus::class)]
    private TaskStatus $status = TaskStatus::Backlog;

    #[ORM\Column(enumType: TaskPriority::class)]
    private TaskPriority $priority = TaskPriority::Medium;

    /**
     * Progression de 0 à 100 (%).
     */
    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $progress = 0;

    /**
     * Agent IA assigné à cette tâche.
     */
    #[ORM\ManyToOne(targetEntity: Agent::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Agent $assignedAgent = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Project      $project,
        TaskType     $type,
        string       $title,
        ?string      $description = null,
        TaskPriority $priority    = TaskPriority::Medium,
    ) {
        $this->id          = Uuid::v7();
        $this->project     = $project;
        $this->type        = $type;
        $this->title       = $title;
        $this->description = $description;
        $this->priority    = $priority;
        $this->createdAt   = new \DateTimeImmutable();
        $this->updatedAt   = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid                      { return $this->id; }
    public function getProject(): Project              { return $this->project; }
    public function getFeature(): ?Feature             { return $this->feature; }
    public function getParent(): ?Task                 { return $this->parent; }
    public function getType(): TaskType                { return $this->type; }
    public function getTitle(): string                 { return $this->title; }
    public function getDescription(): ?string          { return $this->description; }
    public function getStatus(): TaskStatus            { return $this->status; }
    public function getPriority(): TaskPriority        { return $this->priority; }
    public function getProgress(): int                 { return $this->progress; }
    public function getAssignedAgent(): ?Agent         { return $this->assignedAgent; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function setFeature(?Feature $f): static        { $this->feature = $f; return $this; }
    public function setParent(?Task $t): static            { $this->parent = $t; return $this; }
    public function setTitle(string $title): static        { $this->title = $title; return $this; }
    public function setDescription(?string $d): static     { $this->description = $d; return $this; }
    public function setStatus(TaskStatus $s): static       { $this->status = $s; return $this; }
    public function setPriority(TaskPriority $p): static   { $this->priority = $p; return $this; }
    public function setAssignedAgent(?Agent $a): static    { $this->assignedAgent = $a; return $this; }

    public function setProgress(int $progress): static
    {
        $this->progress = max(0, min(100, $progress));
        return $this;
    }
}
