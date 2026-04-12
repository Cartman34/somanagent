<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DispatchMode;
use App\Repository\ProjectRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Represents a project, the top-level aggregate containing modules, tickets, workflows, and an assigned team.
 */
#[ORM\Entity(repositoryClass: ProjectRepository::class)]
#[ORM\Table(name: 'project')]
#[ORM\HasLifecycleCallbacks]
class Project
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $repositoryUrl = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * Team assigned to this project.
     * Determines which agents are available for story execution.
     * Nullable — a project may exist without a team (partial setup).
     */
    #[ORM\ManyToOne(targetEntity: Team::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Team $team = null;

    #[ORM\ManyToOne(targetEntity: Workflow::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Workflow $workflow = null;

    #[ORM\Column(enumType: DispatchMode::class, options: ['default' => DispatchMode::Auto->value])]
    private DispatchMode $dispatchMode = DispatchMode::Auto;

    /**
     * Default role automatically assigned to new UserStory and Bug tickets.
     * When null, no role is assigned at ticket creation.
     */
    #[ORM\ManyToOne(targetEntity: Role::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Role $defaultTicketRole = null;

    #[ORM\OneToMany(targetEntity: Module::class, mappedBy: 'project', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['name' => 'ASC'])]
    private Collection $modules;

    /**
     * Initializes a project aggregate with its required identity fields.
     */
    public function __construct(string $name, ?string $description = null)
    {
        $this->id          = Uuid::v7();
        $this->name        = $name;
        $this->description = $description;
        $this->createdAt   = new \DateTimeImmutable();
        $this->updatedAt   = new \DateTimeImmutable();
        $this->modules     = new ArrayCollection();
    }

    /**
     * Refreshes the update timestamp before Doctrine persists changes.
     */
    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** Returns the project UUID. */
    public function getId(): Uuid                      { return $this->id; }
    /** Returns the project name. */
    public function getName(): string                  { return $this->name; }
    /** Returns the optional project description. */
    public function getDescription(): ?string          { return $this->description; }
    /** Returns the optional repository URL. */
    public function getRepositoryUrl(): ?string        { return $this->repositoryUrl; }
    /** Returns the assigned team, if any. */
    public function getTeam(): ?Team                   { return $this->team; }
    /** Returns the assigned workflow, if any. */
    public function getWorkflow(): ?Workflow           { return $this->workflow; }
    /** Returns the task dispatch mode configured for this project. */
    public function getDispatchMode(): DispatchMode    { return $this->dispatchMode; }
    /** Returns the default role assigned to new UserStory/Bug tickets, if any. */
    public function getDefaultTicketRole(): ?Role      { return $this->defaultTicketRole; }
    /** Returns the project creation timestamp. */
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    /** Returns the latest update timestamp. */
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** @return Collection<int, Module> */
    public function getModules(): Collection { return $this->modules; }

    /** Updates the project name. */
    public function setName(string $name): static               { $this->name = $name; return $this; }
    /** Updates the optional project description. */
    public function setDescription(?string $d): static          { $this->description = $d; return $this; }
    /** Updates the optional repository URL. */
    public function setRepositoryUrl(?string $url): static      { $this->repositoryUrl = $url; return $this; }
    /** Assigns or clears the project team. */
    public function setTeam(?Team $team): static                { $this->team = $team; return $this; }
    /** Assigns or clears the project workflow. */
    public function setWorkflow(?Workflow $workflow): static    { $this->workflow = $workflow; return $this; }
    /** Updates the task dispatch policy. */
    public function setDispatchMode(DispatchMode $dispatchMode): static { $this->dispatchMode = $dispatchMode; return $this; }
    /** Sets the default role assigned to new UserStory/Bug tickets. */
    public function setDefaultTicketRole(?Role $role): static           { $this->defaultTicketRole = $role; return $this; }

    /**
     * Adds one module to this project and synchronizes the owning side.
     */
    public function addModule(Module $module): static
    {
        if (!$this->modules->contains($module)) {
            $this->modules->add($module);
            $module->setProject($this);
        }
        return $this;
    }

    /**
     * Removes one module from this project.
     */
    public function removeModule(Module $module): static
    {
        $this->modules->removeElement($module);
        return $this;
    }
}
