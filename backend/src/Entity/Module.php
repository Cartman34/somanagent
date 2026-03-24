<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ModuleStatus;
use App\Repository\ModuleRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

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
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid                    { return $this->id; }
    public function getProject(): Project            { return $this->project; }
    public function getName(): string                { return $this->name; }
    public function getDescription(): ?string        { return $this->description; }
    public function getRepositoryUrl(): ?string      { return $this->repositoryUrl; }
    public function getStack(): ?string              { return $this->stack; }
    public function getStatus(): ModuleStatus        { return $this->status; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function setProject(Project $project): static        { $this->project = $project; return $this; }
    public function setName(string $name): static               { $this->name = $name; return $this; }
    public function setDescription(?string $d): static          { $this->description = $d; return $this; }
    public function setRepositoryUrl(?string $url): static      { $this->repositoryUrl = $url; return $this; }
    public function setStack(?string $stack): static            { $this->stack = $stack; return $this; }
    public function setStatus(ModuleStatus $status): static     { $this->status = $status; return $this; }

    public function archive(): static
    {
        $this->status = ModuleStatus::Archived;
        return $this;
    }
}
