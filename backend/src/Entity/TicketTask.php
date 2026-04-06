<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TaskPriority;
use App\Enum\TaskStatus;
use App\Repository\TicketTaskRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Represents a subtask of a ticket, linked to an agent action and optionally to an agent or role.
 */
#[ORM\Entity(repositoryClass: TicketTaskRepository::class)]
#[ORM\Table(name: 'ticket_task')]
#[ORM\HasLifecycleCallbacks]
class TicketTask
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Ticket::class, inversedBy: 'tasks')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Ticket $ticket;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?self $parent = null;

    #[ORM\ManyToOne(targetEntity: WorkflowStep::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?WorkflowStep $workflowStep = null;

    #[ORM\ManyToOne(targetEntity: AgentAction::class)]
    #[ORM\JoinColumn(nullable: false)]
    private AgentAction $agentAction;

    #[ORM\ManyToOne(targetEntity: Agent::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Agent $assignedAgent = null;

    #[ORM\ManyToOne(targetEntity: Role::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Role $assignedRole = null;

    #[ORM\ManyToOne(targetEntity: Agent::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Agent $addedBy = null;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(enumType: TaskStatus::class)]
    private TaskStatus $status = TaskStatus::Backlog;

    #[ORM\Column(enumType: TaskPriority::class)]
    private TaskPriority $priority = TaskPriority::Medium;

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $progress = 0;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $branchName = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, AgentTaskExecution> */
    #[ORM\ManyToMany(targetEntity: AgentTaskExecution::class, inversedBy: 'ticketTasks')]
    #[ORM\JoinTable(name: 'ticket_task_agent_task_execution')]
    private Collection $executions;

    /**
     * Creates a ticket task linked to a ticket and an agent action.
     */
    public function __construct(
        Ticket $ticket,
        AgentAction $agentAction,
        string $title,
        ?string $description = null,
        TaskPriority $priority = TaskPriority::Medium,
    ) {
        $this->id = Uuid::v7();
        $this->ticket = $ticket;
        $this->agentAction = $agentAction;
        $this->title = $title;
        $this->description = $description;
        $this->priority = $priority;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->executions = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    /**
     * Updates the modification timestamp before Doctrine persists an update.
     */
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** Returns the task identifier. */
    public function getId(): Uuid { return $this->id; }
    /** Returns the parent ticket. */
    public function getTicket(): Ticket { return $this->ticket; }
    /** Returns the parent task, if any. */
    public function getParent(): ?self { return $this->parent; }
    /** Returns the linked workflow step, if any. */
    public function getWorkflowStep(): ?WorkflowStep { return $this->workflowStep; }
    /** Returns the action executed by this task. */
    public function getAgentAction(): AgentAction { return $this->agentAction; }
    /** Returns the assigned agent, if any. */
    public function getAssignedAgent(): ?Agent { return $this->assignedAgent; }
    /** Returns the assigned role, if any. */
    public function getAssignedRole(): ?Role { return $this->assignedRole; }
    /** Returns the agent that created the task, if any. */
    public function getAddedBy(): ?Agent { return $this->addedBy; }
    /** Returns the task title. */
    public function getTitle(): string { return $this->title; }
    /** Returns the optional task description. */
    public function getDescription(): ?string { return $this->description; }
    /** Returns the current task status. */
    public function getStatus(): TaskStatus { return $this->status; }
    /** Returns the current task priority. */
    public function getPriority(): TaskPriority { return $this->priority; }
    /** Returns the completion progress percentage. */
    public function getProgress(): int { return $this->progress; }
    /** Returns the linked branch name, if any. */
    public function getBranchName(): ?string { return $this->branchName; }
    /** Returns when the task was created. */
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    /** Returns when the task was last updated. */
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** @return Collection<int, AgentTaskExecution> */
    public function getExecutions(): Collection
    {
        return $this->executions;
    }

    /** Assigns or clears the parent task. */
    public function setParent(?self $parent): static { $this->parent = $parent; return $this; }
    /** Assigns or clears the linked workflow step. */
    public function setWorkflowStep(?WorkflowStep $workflowStep): static { $this->workflowStep = $workflowStep; return $this; }
    /** Replaces the agent action associated with the task. */
    public function setAgentAction(AgentAction $agentAction): static { $this->agentAction = $agentAction; return $this; }
    /** Assigns or clears the task agent. */
    public function setAssignedAgent(?Agent $assignedAgent): static { $this->assignedAgent = $assignedAgent; return $this; }
    /** Assigns or clears the task role. */
    public function setAssignedRole(?Role $assignedRole): static { $this->assignedRole = $assignedRole; return $this; }
    /** Stores which agent created the task. */
    public function setAddedBy(?Agent $addedBy): static { $this->addedBy = $addedBy; return $this; }
    /** Updates the task title. */
    public function setTitle(string $title): static { $this->title = $title; return $this; }
    /** Updates the task description. */
    public function setDescription(?string $description): static { $this->description = $description; return $this; }
    /** Updates the task status. */
    public function setStatus(TaskStatus $status): static { $this->status = $status; return $this; }
    /** Updates the task priority. */
    public function setPriority(TaskPriority $priority): static { $this->priority = $priority; return $this; }
    /** Updates the linked branch name. */
    public function setBranchName(?string $branchName): static { $this->branchName = $branchName; return $this; }

    /**
     * Updates the completion progress while clamping the value between 0 and 100.
     */
    public function setProgress(int $progress): static
    {
        $this->progress = max(0, min(100, $progress));

        return $this;
    }

    /**
     * Links an execution to the task if it is not already present.
     */
    public function addExecution(AgentTaskExecution $execution): static
    {
        if (!$this->executions->contains($execution)) {
            $this->executions->add($execution);
            $execution->addTicketTask($this);
        }

        return $this;
    }
}
