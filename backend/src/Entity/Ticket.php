<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TaskPriority;
use App\Enum\TaskStatus;
use App\Enum\TaskType;
use App\Repository\TicketRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Represents a ticket (user story, bug, etc.) within a project, containing tasks, logs, and assignment info.
 */
#[ORM\Entity(repositoryClass: TicketRepository::class)]
#[ORM\Table(name: 'ticket')]
#[ORM\HasLifecycleCallbacks]
class Ticket
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Project $project;

    #[ORM\ManyToOne(targetEntity: Feature::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Feature $feature = null;

    #[ORM\ManyToOne(targetEntity: WorkflowStep::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?WorkflowStep $workflowStep = null;

    #[ORM\Column(enumType: TaskType::class)]
    private TaskType $type;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $initialRequest = null;

    #[ORM\Column(enumType: TaskStatus::class)]
    private TaskStatus $status = TaskStatus::Backlog;

    #[ORM\Column(enumType: TaskPriority::class)]
    private TaskPriority $priority = TaskPriority::Medium;

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $progress = 0;

    #[ORM\ManyToOne(targetEntity: Agent::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Agent $assignedAgent = null;

    #[ORM\ManyToOne(targetEntity: Role::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Role $assignedRole = null;

    #[ORM\ManyToOne(targetEntity: Agent::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Agent $addedBy = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $branchName = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, TicketTask> */
    #[ORM\OneToMany(mappedBy: 'ticket', targetEntity: TicketTask::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $tasks;

    /** @var Collection<int, TicketLog> */
    #[ORM\OneToMany(mappedBy: 'ticket', targetEntity: TicketLog::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $logs;

    /**
     * Creates a ticket within a project for a story or bug type.
     */
    public function __construct(
        Project $project,
        TaskType $type,
        string $title,
        ?string $description = null,
        TaskPriority $priority = TaskPriority::Medium,
    ) {
        if ($type === TaskType::Task) {
            throw new \InvalidArgumentException('Ticket cannot use the operational task type.');
        }

        $this->id = Uuid::v7();
        $this->project = $project;
        $this->type = $type;
        $this->title = $title;
        $this->description = $description;
        $this->priority = $priority;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->tasks = new ArrayCollection();
        $this->logs = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    /**
     * Updates the modification timestamp before Doctrine persists an update.
     */
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** Returns the ticket identifier. */
    public function getId(): Uuid { return $this->id; }
    /** Returns the project owning the ticket. */
    public function getProject(): Project { return $this->project; }
    /** Returns the feature linked to the ticket, if any. */
    public function getFeature(): ?Feature { return $this->feature; }
    /** Returns the current workflow step, if any. */
    public function getWorkflowStep(): ?WorkflowStep { return $this->workflowStep; }
    /** Returns the ticket type. */
    public function getType(): TaskType { return $this->type; }
    /** Returns the ticket title. */
    public function getTitle(): string { return $this->title; }
    /** Returns the optional ticket description. */
    public function getDescription(): ?string { return $this->description; }
    /** Returns the original user request submitted when the ticket was created, if any. */
    public function getInitialRequest(): ?string { return $this->initialRequest; }
    /** Returns the current ticket status. */
    public function getStatus(): TaskStatus { return $this->status; }
    /** Returns the current ticket priority. */
    public function getPriority(): TaskPriority { return $this->priority; }
    /** Returns the completion progress percentage. */
    public function getProgress(): int { return $this->progress; }
    /** Returns the agent assigned to the ticket, if any. */
    public function getAssignedAgent(): ?Agent { return $this->assignedAgent; }
    /** Returns the role assigned to the ticket, if any. */
    public function getAssignedRole(): ?Role { return $this->assignedRole; }
    /** Returns the agent that created the ticket, if any. */
    public function getAddedBy(): ?Agent { return $this->addedBy; }
    /** Returns the linked branch name, if any. */
    public function getBranchName(): ?string { return $this->branchName; }
    /** Returns when the ticket was created. */
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    /** Returns when the ticket was last updated. */
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** @return Collection<int, TicketTask> */
    public function getTasks(): Collection
    {
        return $this->tasks;
    }

    /** @return Collection<int, TicketLog> */
    public function getLogs(): Collection
    {
        return $this->logs;
    }

    /**
     * Indicates whether the ticket represents a user story or a bug.
     */
    public function isStory(): bool
    {
        return $this->type === TaskType::UserStory || $this->type === TaskType::Bug;
    }

    /** Assigns or clears the linked feature. */
    public function setFeature(?Feature $feature): static { $this->feature = $feature; return $this; }
    /** Assigns or clears the current workflow step. */
    public function setWorkflowStep(?WorkflowStep $workflowStep): static { $this->workflowStep = $workflowStep; return $this; }
    /** Updates the ticket title. */
    public function setTitle(string $title): static { $this->title = $title; return $this; }
    /** Updates the ticket description. */
    public function setDescription(?string $description): static { $this->description = $description; return $this; }
    /** Stores the original user request submitted when the ticket was created. */
    public function setInitialRequest(?string $initialRequest): static { $this->initialRequest = $initialRequest; return $this; }
    /** Updates the ticket status. */
    public function setStatus(TaskStatus $status): static { $this->status = $status; return $this; }
    /** Updates the ticket priority. */
    public function setPriority(TaskPriority $priority): static { $this->priority = $priority; return $this; }
    /** Assigns or clears the ticket agent. */
    public function setAssignedAgent(?Agent $assignedAgent): static { $this->assignedAgent = $assignedAgent; return $this; }
    /** Assigns or clears the ticket role. */
    public function setAssignedRole(?Role $assignedRole): static { $this->assignedRole = $assignedRole; return $this; }
    /** Stores which agent created the ticket. */
    public function setAddedBy(?Agent $addedBy): static { $this->addedBy = $addedBy; return $this; }
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
}
