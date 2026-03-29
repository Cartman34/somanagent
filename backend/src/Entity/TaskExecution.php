<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TaskExecutionStatus;
use App\Enum\TaskExecutionTrigger;
use App\Repository\TaskExecutionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TaskExecutionRepository::class)]
#[ORM\Table(name: 'task_execution')]
#[ORM\Index(columns: ['task_id'], name: 'idx_task_execution_task')]
#[ORM\Index(columns: ['trace_ref'], name: 'idx_task_execution_trace_ref')]
#[ORM\Index(columns: ['status'], name: 'idx_task_execution_status')]
#[ORM\HasLifecycleCallbacks]
class TaskExecution
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Task::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Task $task;

    #[ORM\Column(length: 64, unique: true)]
    private string $traceRef;

    #[ORM\Column(enumType: TaskExecutionTrigger::class)]
    private TaskExecutionTrigger $triggerType;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $workflowStepKey = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $skillSlug = null;

    #[ORM\ManyToOne(targetEntity: Agent::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Agent $requestedAgent = null;

    #[ORM\ManyToOne(targetEntity: Agent::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Agent $effectiveAgent = null;

    #[ORM\Column(enumType: TaskExecutionStatus::class)]
    private TaskExecutionStatus $status = TaskExecutionStatus::Pending;

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $currentAttempt = 0;

    #[ORM\Column(type: 'smallint')]
    private int $maxAttempts = 1;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $requestRef = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastErrorMessage = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $lastErrorScope = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /** @var Collection<int, TaskExecutionAttempt> */
    #[ORM\OneToMany(mappedBy: 'execution', targetEntity: TaskExecutionAttempt::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['attemptNumber' => 'ASC'])]
    private Collection $attempts;

    public function __construct(Task $task, string $traceRef, TaskExecutionTrigger $triggerType, int $maxAttempts)
    {
        $this->id = Uuid::v7();
        $this->task = $task;
        $this->traceRef = $traceRef;
        $this->triggerType = $triggerType;
        $this->maxAttempts = max(1, $maxAttempts);
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->attempts = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getTask(): Task { return $this->task; }
    public function getTraceRef(): string { return $this->traceRef; }
    public function getTriggerType(): TaskExecutionTrigger { return $this->triggerType; }
    public function getWorkflowStepKey(): ?string { return $this->workflowStepKey; }
    public function getSkillSlug(): ?string { return $this->skillSlug; }
    public function getRequestedAgent(): ?Agent { return $this->requestedAgent; }
    public function getEffectiveAgent(): ?Agent { return $this->effectiveAgent; }
    public function getStatus(): TaskExecutionStatus { return $this->status; }
    public function getCurrentAttempt(): int { return $this->currentAttempt; }
    public function getMaxAttempts(): int { return $this->maxAttempts; }
    public function getRequestRef(): ?string { return $this->requestRef; }
    public function getLastErrorMessage(): ?string { return $this->lastErrorMessage; }
    public function getLastErrorScope(): ?string { return $this->lastErrorScope; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function getStartedAt(): ?\DateTimeImmutable { return $this->startedAt; }
    public function getFinishedAt(): ?\DateTimeImmutable { return $this->finishedAt; }

    /**
     * Returns the attempts recorded for this execution in ascending attempt order.
     *
     * @return Collection<int, TaskExecutionAttempt>
     */
    public function getAttempts(): Collection
    {
        return $this->attempts;
    }

    public function setWorkflowStepKey(?string $workflowStepKey): static { $this->workflowStepKey = $workflowStepKey; return $this; }
    public function setSkillSlug(?string $skillSlug): static { $this->skillSlug = $skillSlug; return $this; }
    public function setRequestedAgent(?Agent $requestedAgent): static { $this->requestedAgent = $requestedAgent; return $this; }
    public function setEffectiveAgent(?Agent $effectiveAgent): static { $this->effectiveAgent = $effectiveAgent; return $this; }
    public function setStatus(TaskExecutionStatus $status): static { $this->status = $status; return $this; }
    public function setCurrentAttempt(int $currentAttempt): static { $this->currentAttempt = max(0, $currentAttempt); return $this; }
    public function setRequestRef(?string $requestRef): static { $this->requestRef = $requestRef; return $this; }
    public function setLastErrorMessage(?string $lastErrorMessage): static { $this->lastErrorMessage = $lastErrorMessage; return $this; }
    public function setLastErrorScope(?string $lastErrorScope): static { $this->lastErrorScope = $lastErrorScope; return $this; }
    public function setStartedAt(?\DateTimeImmutable $startedAt): static { $this->startedAt = $startedAt; return $this; }
    public function setFinishedAt(?\DateTimeImmutable $finishedAt): static { $this->finishedAt = $finishedAt; return $this; }

    public function addAttempt(TaskExecutionAttempt $attempt): static
    {
        if (!$this->attempts->contains($attempt)) {
            $this->attempts->add($attempt);
        }

        return $this;
    }
}
