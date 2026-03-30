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
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid                      { return $this->id; }
    public function getName(): string                  { return $this->name; }
    public function getDescription(): ?string          { return $this->description; }
    public function getTrigger(): WorkflowTrigger      { return $this->trigger; }
    public function getStatus(): WorkflowStatus        { return $this->status; }
    public function isActive(): bool                   { return $this->isActive; }
    public function isEditable(): bool                 { return !$this->isActive && $this->status === WorkflowStatus::Validated; }
    public function isUsable(): bool                   { return $this->status->isUsable() && $this->isActive; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** @return Collection<int, WorkflowStep> */
    public function getSteps(): Collection { return $this->steps; }

    public function setName(string $name): static
    {
        $this->assertEditable();
        $this->name = $name;
        return $this;
    }

    public function setDescription(?string $d): static
    {
        $this->assertEditable();
        $this->description = $d;
        return $this;
    }

    public function setTrigger(WorkflowTrigger $t): static
    {
        $this->assertEditable();
        $this->trigger = $t;
        return $this;
    }

    public function validate(): static
    {
        $this->status = WorkflowStatus::Validated;
        return $this;
    }

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

    public function addStep(WorkflowStep $step): static
    {
        $this->assertEditable();
        if (!$this->steps->contains($step)) {
            $this->steps->add($step);
            $step->setWorkflow($this);
        }
        return $this;
    }

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
