<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Entity;

use App\Enum\StoryStatus;
use App\Enum\TaskPriority;
use App\Enum\TaskStatus;
use App\Enum\TaskType;
use App\Repository\TicketRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

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

    #[ORM\Column(enumType: TaskStatus::class)]
    private TaskStatus $status = TaskStatus::Backlog;

    #[ORM\Column(enumType: StoryStatus::class, nullable: true)]
    private ?StoryStatus $storyStatus = null;

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
        $this->storyStatus = StoryStatus::New;
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getProject(): Project { return $this->project; }
    public function getFeature(): ?Feature { return $this->feature; }
    public function getWorkflowStep(): ?WorkflowStep { return $this->workflowStep; }
    public function getType(): TaskType { return $this->type; }
    public function getTitle(): string { return $this->title; }
    public function getDescription(): ?string { return $this->description; }
    public function getStatus(): TaskStatus { return $this->status; }
    public function getStoryStatus(): ?StoryStatus { return $this->storyStatus; }
    public function getPriority(): TaskPriority { return $this->priority; }
    public function getProgress(): int { return $this->progress; }
    public function getAssignedAgent(): ?Agent { return $this->assignedAgent; }
    public function getAssignedRole(): ?Role { return $this->assignedRole; }
    public function getAddedBy(): ?Agent { return $this->addedBy; }
    public function getBranchName(): ?string { return $this->branchName; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
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

    public function isStory(): bool
    {
        return $this->type === TaskType::UserStory || $this->type === TaskType::Bug;
    }

    public function setFeature(?Feature $feature): static { $this->feature = $feature; return $this; }
    public function setWorkflowStep(?WorkflowStep $workflowStep): static { $this->workflowStep = $workflowStep; return $this; }
    public function setTitle(string $title): static { $this->title = $title; return $this; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }
    public function setStatus(TaskStatus $status): static { $this->status = $status; return $this; }
    public function setStoryStatus(?StoryStatus $storyStatus): static { $this->storyStatus = $storyStatus; return $this; }
    public function setPriority(TaskPriority $priority): static { $this->priority = $priority; return $this; }
    public function setAssignedAgent(?Agent $assignedAgent): static { $this->assignedAgent = $assignedAgent; return $this; }
    public function setAssignedRole(?Role $assignedRole): static { $this->assignedRole = $assignedRole; return $this; }
    public function setAddedBy(?Agent $addedBy): static { $this->addedBy = $addedBy; return $this; }
    public function setBranchName(?string $branchName): static { $this->branchName = $branchName; return $this; }

    public function setProgress(int $progress): static
    {
        $this->progress = max(0, min(100, $progress));

        return $this;
    }

    public function transitionStoryTo(StoryStatus $next): static
    {
        if (!$this->isStory()) {
            throw new \LogicException('Story status transitions are only valid for ticket stories and bugs.');
        }

        if ($this->storyStatus === null || !$this->storyStatus->canTransitionTo($next)) {
            $current = $this->storyStatus?->value ?? 'null';
            throw new \LogicException("Cannot transition story from '{$current}' to '{$next->value}'.");
        }

        $this->storyStatus = $next;

        return $this;
    }
}
