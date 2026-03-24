<?php

declare(strict_types=1);

namespace App\Entity;

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

    #[ORM\ManyToOne(targetEntity: Team::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Team $team = null;

    #[ORM\Column]
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

    public function getId(): Uuid                    { return $this->id; }
    public function getName(): string                { return $this->name; }
    public function getDescription(): ?string        { return $this->description; }
    public function getTrigger(): WorkflowTrigger    { return $this->trigger; }
    public function getTeam(): ?Team                 { return $this->team; }
    public function isActive(): bool                 { return $this->isActive; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** @return Collection<int, WorkflowStep> */
    public function getSteps(): Collection { return $this->steps; }

    public function setName(string $name): static                 { $this->name = $name; return $this; }
    public function setDescription(?string $d): static            { $this->description = $d; return $this; }
    public function setTrigger(WorkflowTrigger $t): static        { $this->trigger = $t; return $this; }
    public function setTeam(?Team $team): static                  { $this->team = $team; return $this; }
    public function setIsActive(bool $active): static             { $this->isActive = $active; return $this; }

    public function addStep(WorkflowStep $step): static
    {
        if (!$this->steps->contains($step)) {
            $this->steps->add($step);
            $step->setWorkflow($this);
        }
        return $this;
    }

    public function removeStep(WorkflowStep $step): static
    {
        $this->steps->removeElement($step);
        return $this;
    }
}
