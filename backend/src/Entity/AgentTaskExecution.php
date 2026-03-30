<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TaskExecutionStatus;
use App\Enum\TaskExecutionTrigger;
use App\Repository\AgentTaskExecutionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AgentTaskExecutionRepository::class)]
#[ORM\Table(name: 'agent_task_execution')]
#[ORM\Index(columns: ['trace_ref'], name: 'idx_agent_task_execution_trace_ref')]
#[ORM\Index(columns: ['status'], name: 'idx_agent_task_execution_status')]
#[ORM\HasLifecycleCallbacks]
class AgentTaskExecution
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 64, unique: true)]
    private string $traceRef;

    #[ORM\Column(enumType: TaskExecutionTrigger::class)]
    private TaskExecutionTrigger $triggerType;

    #[ORM\ManyToOne(targetEntity: AgentAction::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?AgentAction $agentAction = null;

    #[ORM\Column(length: 255)]
    private string $actionKey;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $actionLabel = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $roleSlug = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $skillSlug = null;

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

    #[ORM\ManyToOne(targetEntity: Agent::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Agent $requestedAgent = null;

    #[ORM\ManyToOne(targetEntity: Agent::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Agent $effectiveAgent = null;

    /** @var Collection<int, AgentTaskExecutionAttempt> */
    #[ORM\OneToMany(mappedBy: 'execution', targetEntity: AgentTaskExecutionAttempt::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['attemptNumber' => 'ASC'])]
    private Collection $attempts;

    /** @var Collection<int, TicketTask> */
    #[ORM\ManyToMany(targetEntity: TicketTask::class, mappedBy: 'executions')]
    private Collection $ticketTasks;

    public function __construct(string $traceRef, TaskExecutionTrigger $triggerType, string $actionKey, int $maxAttempts)
    {
        $this->id = Uuid::v7();
        $this->traceRef = $traceRef;
        $this->triggerType = $triggerType;
        $this->actionKey = $actionKey;
        $this->maxAttempts = max(1, $maxAttempts);
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->attempts = new ArrayCollection();
        $this->ticketTasks = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getTraceRef(): string { return $this->traceRef; }
    public function getTriggerType(): TaskExecutionTrigger { return $this->triggerType; }
    public function getAgentAction(): ?AgentAction { return $this->agentAction; }
    public function getActionKey(): string { return $this->actionKey; }
    public function getActionLabel(): ?string { return $this->actionLabel; }
    public function getRoleSlug(): ?string { return $this->roleSlug; }
    public function getSkillSlug(): ?string { return $this->skillSlug; }
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
    public function getRequestedAgent(): ?Agent { return $this->requestedAgent; }
    public function getEffectiveAgent(): ?Agent { return $this->effectiveAgent; }

    /** @return Collection<int, AgentTaskExecutionAttempt> */
    public function getAttempts(): Collection
    {
        return $this->attempts;
    }

    /** @return Collection<int, TicketTask> */
    public function getTicketTasks(): Collection
    {
        return $this->ticketTasks;
    }

    public function setAgentAction(?AgentAction $agentAction): static { $this->agentAction = $agentAction; return $this; }
    public function setActionLabel(?string $actionLabel): static { $this->actionLabel = $actionLabel; return $this; }
    public function setRoleSlug(?string $roleSlug): static { $this->roleSlug = $roleSlug; return $this; }
    public function setSkillSlug(?string $skillSlug): static { $this->skillSlug = $skillSlug; return $this; }
    public function setStatus(TaskExecutionStatus $status): static { $this->status = $status; return $this; }
    public function setCurrentAttempt(int $currentAttempt): static { $this->currentAttempt = max(0, $currentAttempt); return $this; }
    public function setRequestRef(?string $requestRef): static { $this->requestRef = $requestRef; return $this; }
    public function setLastErrorMessage(?string $lastErrorMessage): static { $this->lastErrorMessage = $lastErrorMessage; return $this; }
    public function setLastErrorScope(?string $lastErrorScope): static { $this->lastErrorScope = $lastErrorScope; return $this; }
    public function setStartedAt(?\DateTimeImmutable $startedAt): static { $this->startedAt = $startedAt; return $this; }
    public function setFinishedAt(?\DateTimeImmutable $finishedAt): static { $this->finishedAt = $finishedAt; return $this; }
    public function setRequestedAgent(?Agent $requestedAgent): static { $this->requestedAgent = $requestedAgent; return $this; }
    public function setEffectiveAgent(?Agent $effectiveAgent): static { $this->effectiveAgent = $effectiveAgent; return $this; }

    public function addAttempt(AgentTaskExecutionAttempt $attempt): static
    {
        if (!$this->attempts->contains($attempt)) {
            $this->attempts->add($attempt);
        }

        return $this;
    }

    public function addTicketTask(TicketTask $ticketTask): static
    {
        if (!$this->ticketTasks->contains($ticketTask)) {
            $this->ticketTasks->add($ticketTask);
        }

        return $this;
    }
}
