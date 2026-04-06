<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Entity;

use App\Enum\WorkflowStatus;
use App\Enum\WorkflowTrigger;
use App\Repository\WorkflowRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Represents a workflow composed of ordered steps that define automated task processing pipelines.
 */
#[ORM\Entity(repositoryClass: WorkflowRepository::class)]
#[ORM\Table(name: 'workflow')]
#[ORM\HasLifecycleCallbacks]
class Workflow
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(enumType: WorkflowTrigger::class)]
    private WorkflowTrigger $trigger;

    #[ORM\Column(enumType: WorkflowStatus::class)]
    private WorkflowStatus $status = WorkflowStatus::Validated;

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\OneToMany(targetEntity: WorkflowStep::class, mappedBy: 'workflow', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['stepOrder' => 'ASC'])]
    private Collection $steps;

    /**
     * Creates a workflow with its name, trigger type, and optional description.
     */
    public function __construct(
        string          $name,
        WorkflowTrigger $trigger = WorkflowTrigger::Manual,
        ?string         $description = null,
    ) {
        $this->id          = Uuid::v7();
        $this->name        = $name;
        $this->trigger     = $trigger;
        $this->description = $description;
        $this->createdAt   = new \DateTimeImmutable();
        $this->updatedAt   = new \DateTimeImmutable();
        $this->steps       = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    /**
     * Updates the modification timestamp before Doctrine persists an update.
     */
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** Returns the workflow identifier. */
    public function getId(): Uuid                      { return $this->id; }
    /** Returns the workflow name. */
    public function getName(): string                  { return $this->name; }
    /** Returns the optional workflow description. */
    public function getDescription(): ?string          { return $this->description; }
    /** Returns the workflow trigger mode. */
    public function getTrigger(): WorkflowTrigger      { return $this->trigger; }
    /** Returns the workflow lifecycle status. */
    public function getStatus(): WorkflowStatus        { return $this->status; }
    /** Indicates whether the workflow is active for runtime selection. */
    public function isActive(): bool                   { return $this->isActive; }
    /** Indicates whether the workflow can currently be edited. */
    public function isEditable(): bool                 { return !$this->isActive && $this->status === WorkflowStatus::Validated; }
    /** Indicates whether the workflow is valid for runtime use. */
    public function isUsable(): bool                   { return $this->status->isUsable() && $this->isActive; }
    /** Returns when the workflow was created. */
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    /** Returns when the workflow was last updated. */
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** @return Collection<int, WorkflowStep> */
    public function getSteps(): Collection { return $this->steps; }

    /**
     * Updates the workflow name while it remains editable.
     */
    public function setName(string $name): static
    {
        $this->assertEditable();
        $this->name = $name;
        return $this;
    }

    /**
     * Updates the workflow description while it remains editable.
     */
    public function setDescription(?string $d): static
    {
        $this->assertEditable();
        $this->description = $d;
        return $this;
    }

    /**
     * Updates the workflow trigger while it remains editable.
     */
    public function setTrigger(WorkflowTrigger $t): static
    {
        $this->assertEditable();
        $this->trigger = $t;
        return $this;
    }

    /**
     * Marks the workflow as validated.
     */
    public function validate(): static
    {
        $this->status = WorkflowStatus::Validated;
        return $this;
    }

    /**
     * Locks a validated workflow against further edits.
     */
    public function lock(): static
    {
        if ($this->status !== WorkflowStatus::Validated) {
            throw new \LogicException("Only a validated workflow can be locked.");
        }
        $this->status = WorkflowStatus::Locked;
        return $this;
    }

    /**
     * Marks the workflow as available for runtime selection.
     */
    public function activate(): static
    {
        $this->isActive = true;

        return $this;
    }

    /**
     * Marks the workflow as unavailable for future runtime selection.
     */
    public function deactivate(): static
    {
        $this->isActive = false;

        return $this;
    }

    /**
     * Adds a step to the workflow while preserving editability constraints.
     */
    public function addStep(WorkflowStep $step): static
    {
        $this->assertEditable();
        if (!$this->steps->contains($step)) {
            $this->steps->add($step);
            $step->setWorkflow($this);
        }
        return $this;
    }

    /**
     * Removes a step from the workflow while preserving editability constraints.
     */
    public function removeStep(WorkflowStep $step): static
    {
        $this->assertEditable();
        $this->steps->removeElement($step);
        return $this;
    }

    private function assertEditable(): void
    {
        if (!$this->isEditable()) {
            throw new \LogicException("Workflow '{$this->name}' is not editable.");
        }
    }
}
