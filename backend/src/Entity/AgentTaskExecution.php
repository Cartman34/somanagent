<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TaskExecutionStatus;
use App\Enum\TaskExecutionTrigger;
use App\Repository\AgentTaskExecutionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Tracks the execution of a task by an agent, including status, attempts, and error tracking.
 */
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

    /**
     * Creates an execution tracker for an agent action request.
     */
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
    /**
     * Updates the modification timestamp before Doctrine persists an update.
     */
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** Returns the execution identifier. */
    public function getId(): Uuid { return $this->id; }
    /** Returns the unique trace reference. */
    public function getTraceRef(): string { return $this->traceRef; }
    /** Returns the trigger type that created the execution. */
    public function getTriggerType(): TaskExecutionTrigger { return $this->triggerType; }
    /** Returns the linked agent action, if one is attached. */
    public function getAgentAction(): ?AgentAction { return $this->agentAction; }
    /** Returns the action key requested for the execution. */
    public function getActionKey(): string { return $this->actionKey; }
    /** Returns the optional display label of the action. */
    public function getActionLabel(): ?string { return $this->actionLabel; }
    /** Returns the requested role slug, if any. */
    public function getRoleSlug(): ?string { return $this->roleSlug; }
    /** Returns the requested skill slug, if any. */
    public function getSkillSlug(): ?string { return $this->skillSlug; }
    /** Returns the overall execution status. */
    public function getStatus(): TaskExecutionStatus { return $this->status; }
    /** Returns the current retry attempt index. */
    public function getCurrentAttempt(): int { return $this->currentAttempt; }
    /** Returns the maximum allowed number of attempts. */
    public function getMaxAttempts(): int { return $this->maxAttempts; }
    /** Returns the request correlation reference, if any. */
    public function getRequestRef(): ?string { return $this->requestRef; }
    /** Returns the last error message recorded on the execution. */
    public function getLastErrorMessage(): ?string { return $this->lastErrorMessage; }
    /** Returns the scope of the last recorded error. */
    public function getLastErrorScope(): ?string { return $this->lastErrorScope; }
    /** Returns when the execution record was created. */
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    /** Returns when the execution record was last updated. */
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    /** Returns when the execution started. */
    public function getStartedAt(): ?\DateTimeImmutable { return $this->startedAt; }
    /** Returns when the execution finished. */
    public function getFinishedAt(): ?\DateTimeImmutable { return $this->finishedAt; }
    /** Returns the agent originally requested to handle the execution. */
    public function getRequestedAgent(): ?Agent { return $this->requestedAgent; }
    /** Returns the effective agent that handled the execution. */
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

    /** Links an agent action entity to the execution. */
    public function setAgentAction(?AgentAction $agentAction): static { $this->agentAction = $agentAction; return $this; }
    /** Stores the display label of the requested action. */
    public function setActionLabel(?string $actionLabel): static { $this->actionLabel = $actionLabel; return $this; }
    /** Stores the requested role slug. */
    public function setRoleSlug(?string $roleSlug): static { $this->roleSlug = $roleSlug; return $this; }
    /** Stores the requested skill slug. */
    public function setSkillSlug(?string $skillSlug): static { $this->skillSlug = $skillSlug; return $this; }
    /** Updates the overall execution status. */
    public function setStatus(TaskExecutionStatus $status): static { $this->status = $status; return $this; }
    /** Updates the current attempt counter. */
    public function setCurrentAttempt(int $currentAttempt): static { $this->currentAttempt = max(0, $currentAttempt); return $this; }
    /** Stores the request correlation reference. */
    public function setRequestRef(?string $requestRef): static { $this->requestRef = $requestRef; return $this; }
    /** Stores the last execution error message. */
    public function setLastErrorMessage(?string $lastErrorMessage): static { $this->lastErrorMessage = $lastErrorMessage; return $this; }
    /** Stores the scope of the last execution error. */
    public function setLastErrorScope(?string $lastErrorScope): static { $this->lastErrorScope = $lastErrorScope; return $this; }
    /** Stores when the execution started. */
    public function setStartedAt(?\DateTimeImmutable $startedAt): static { $this->startedAt = $startedAt; return $this; }
    /** Stores when the execution finished. */
    public function setFinishedAt(?\DateTimeImmutable $finishedAt): static { $this->finishedAt = $finishedAt; return $this; }
    /** Assigns the requested agent. */
    public function setRequestedAgent(?Agent $requestedAgent): static { $this->requestedAgent = $requestedAgent; return $this; }
    /** Assigns the effective agent. */
    public function setEffectiveAgent(?Agent $effectiveAgent): static { $this->effectiveAgent = $effectiveAgent; return $this; }

    /**
     * Attaches an attempt to the execution if it is not already present.
     */
    public function addAttempt(AgentTaskExecutionAttempt $attempt): static
    {
        if (!$this->attempts->contains($attempt)) {
            $this->attempts->add($attempt);
        }

        return $this;
    }

    /**
     * Links a ticket task to this execution if it is not already present.
     */
    public function addTicketTask(TicketTask $ticketTask): static
    {
        if (!$this->ticketTasks->contains($ticketTask)) {
            $this->ticketTasks->add($ticketTask);
        }

        return $this;
    }
}
