<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ModuleStatus;
use App\Repository\ModuleRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Represents a code module or repository belonging to a project.
 */
#[ORM\Entity(repositoryClass: ModuleRepository::class)]
#[ORM\Table(name: 'module')]
#[ORM\HasLifecycleCallbacks]
class Module
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'modules')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Project $project;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $repositoryUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stack = null;

    #[ORM\Column(enumType: ModuleStatus::class)]
    private ModuleStatus $status;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * Creates a module within the given project.
     */
    public function __construct(Project $project, string $name, ?string $description = null)
    {
        $this->id          = Uuid::v7();
        $this->project     = $project;
        $this->name        = $name;
        $this->description = $description;
        $this->status      = ModuleStatus::Active;
        $this->createdAt   = new \DateTimeImmutable();
        $this->updatedAt   = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    /**
     * Updates the modification timestamp before Doctrine persists an update.
     */
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** Returns the module identifier. */
    public function getId(): Uuid                    { return $this->id; }
    /** Returns the project owning the module. */
    public function getProject(): Project            { return $this->project; }
    /** Returns the module name. */
    public function getName(): string                { return $this->name; }
    /** Returns the optional module description. */
    public function getDescription(): ?string        { return $this->description; }
    /** Returns the repository URL, if any. */
    public function getRepositoryUrl(): ?string      { return $this->repositoryUrl; }
    /** Returns the technical stack label, if any. */
    public function getStack(): ?string              { return $this->stack; }
    /** Returns the module lifecycle status. */
    public function getStatus(): ModuleStatus        { return $this->status; }
    /** Returns when the module was created. */
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    /** Returns when the module was last updated. */
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** Reassigns the module to another project. */
    public function setProject(Project $project): static        { $this->project = $project; return $this; }
    /** Updates the module name. */
    public function setName(string $name): static               { $this->name = $name; return $this; }
    /** Updates the module description. */
    public function setDescription(?string $d): static          { $this->description = $d; return $this; }
    /** Updates the repository URL. */
    public function setRepositoryUrl(?string $url): static      { $this->repositoryUrl = $url; return $this; }
    /** Updates the technical stack label. */
    public function setStack(?string $stack): static            { $this->stack = $stack; return $this; }
    /** Updates the module lifecycle status. */
    public function setStatus(ModuleStatus $status): static     { $this->status = $status; return $this; }

    /**
     * Marks the module as archived.
     */
    public function archive(): static
    {
        $this->status = ModuleStatus::Archived;
        return $this;
    }
}
