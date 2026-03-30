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
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getTicket(): Ticket { return $this->ticket; }
    public function getParent(): ?self { return $this->parent; }
    public function getWorkflowStep(): ?WorkflowStep { return $this->workflowStep; }
    public function getAgentAction(): AgentAction { return $this->agentAction; }
    public function getAssignedAgent(): ?Agent { return $this->assignedAgent; }
    public function getAssignedRole(): ?Role { return $this->assignedRole; }
    public function getAddedBy(): ?Agent { return $this->addedBy; }
    public function getTitle(): string { return $this->title; }
    public function getDescription(): ?string { return $this->description; }
    public function getStatus(): TaskStatus { return $this->status; }
    public function getPriority(): TaskPriority { return $this->priority; }
    public function getProgress(): int { return $this->progress; }
    public function getBranchName(): ?string { return $this->branchName; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** @return Collection<int, AgentTaskExecution> */
    public function getExecutions(): Collection
    {
        return $this->executions;
    }

    public function setParent(?self $parent): static { $this->parent = $parent; return $this; }
    public function setWorkflowStep(?WorkflowStep $workflowStep): static { $this->workflowStep = $workflowStep; return $this; }
    public function setAgentAction(AgentAction $agentAction): static { $this->agentAction = $agentAction; return $this; }
    public function setAssignedAgent(?Agent $assignedAgent): static { $this->assignedAgent = $assignedAgent; return $this; }
    public function setAssignedRole(?Role $assignedRole): static { $this->assignedRole = $assignedRole; return $this; }
    public function setAddedBy(?Agent $addedBy): static { $this->addedBy = $addedBy; return $this; }
    public function setTitle(string $title): static { $this->title = $title; return $this; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }
    public function setStatus(TaskStatus $status): static { $this->status = $status; return $this; }
    public function setPriority(TaskPriority $priority): static { $this->priority = $priority; return $this; }
    public function setBranchName(?string $branchName): static { $this->branchName = $branchName; return $this; }

    public function setProgress(int $progress): static
    {
        $this->progress = max(0, min(100, $progress));

        return $this;
    }

    public function addExecution(AgentTaskExecution $execution): static
    {
        if (!$this->executions->contains($execution)) {
            $this->executions->add($execution);
            $execution->addTicketTask($this);
        }

        return $this;
    }
}
