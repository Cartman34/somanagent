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
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid                      { return $this->id; }
    public function getProject(): Project              { return $this->project; }
    public function getName(): string                  { return $this->name; }
    public function getDescription(): ?string          { return $this->description; }
    public function getStatus(): FeatureStatus         { return $this->status; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function setName(string $name): static              { $this->name = $name; return $this; }
    public function setDescription(?string $d): static         { $this->description = $d; return $this; }
    public function setStatus(FeatureStatus $s): static        { $this->status = $s; return $this; }
}
