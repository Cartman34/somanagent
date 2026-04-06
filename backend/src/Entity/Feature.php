<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Entity;

use App\Enum\FeatureStatus;
use App\Repository\FeatureRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Represents a feature or epic within a project that groups related tickets.
 */
#[ORM\Entity(repositoryClass: FeatureRepository::class)]
#[ORM\Table(name: 'feature')]
#[ORM\HasLifecycleCallbacks]
class Feature
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Project $project;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(enumType: FeatureStatus::class)]
    private FeatureStatus $status = FeatureStatus::Open;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * Creates a feature within the given project.
     */
    public function __construct(Project $project, string $name, ?string $description = null)
    {
        $this->id          = Uuid::v7();
        $this->project     = $project;
        $this->name        = $name;
        $this->description = $description;
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

    /** Returns the feature identifier. */
    public function getId(): Uuid                      { return $this->id; }
    /** Returns the project owning the feature. */
    public function getProject(): Project              { return $this->project; }
    /** Returns the feature name. */
    public function getName(): string                  { return $this->name; }
    /** Returns the optional feature description. */
    public function getDescription(): ?string          { return $this->description; }
    /** Returns the current feature status. */
    public function getStatus(): FeatureStatus         { return $this->status; }
    /** Returns when the feature was created. */
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    /** Returns when the feature was last updated. */
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** Updates the feature name. */
    public function setName(string $name): static              { $this->name = $name; return $this; }
    /** Updates the feature description. */
    public function setDescription(?string $d): static         { $this->description = $d; return $this; }
    /** Updates the feature status. */
    public function setStatus(FeatureStatus $s): static        { $this->status = $s; return $this; }
}
