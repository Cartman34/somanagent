<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'modules')]
#[ORM\HasLifecycleCallbacks]
class ModuleEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ProjectEntity::class, inversedBy: 'modules')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ProjectEntity $project;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $techStack = null;

    #[ORM\Column(type: 'string', length: 512, nullable: true)]
    private ?string $repositoryUrl = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $repositoryBranch = 'main';

    #[ORM\Column(type: 'string', length: 50)]
    private string $status = 'active';

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getProject(): ProjectEntity { return $this->project; }
    public function setProject(ProjectEntity $project): void { $this->project = $project; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $d): void { $this->description = $d; }
    public function getTechStack(): ?string { return $this->techStack; }
    public function setTechStack(?string $t): void { $this->techStack = $t; }
    public function getRepositoryUrl(): ?string { return $this->repositoryUrl; }
    public function setRepositoryUrl(?string $u): void { $this->repositoryUrl = $u; }
    public function getRepositoryBranch(): ?string { return $this->repositoryBranch; }
    public function setRepositoryBranch(?string $b): void { $this->repositoryBranch = $b; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $s): void { $this->status = $s; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
