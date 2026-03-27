<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProjectRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

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

    #[ORM\OneToMany(targetEntity: Module::class, mappedBy: 'project', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['name' => 'ASC'])]
    private Collection $modules;

    public function __construct(string $name, ?string $description = null)
    {
        $this->id          = Uuid::v7();
        $this->name        = $name;
        $this->description = $description;
        $this->createdAt   = new \DateTimeImmutable();
        $this->updatedAt   = new \DateTimeImmutable();
        $this->modules     = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid                      { return $this->id; }
    public function getName(): string                  { return $this->name; }
    public function getDescription(): ?string          { return $this->description; }
    public function getRepositoryUrl(): ?string        { return $this->repositoryUrl; }
    public function getTeam(): ?Team                   { return $this->team; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** @return Collection<int, Module> */
    public function getModules(): Collection { return $this->modules; }

    public function setName(string $name): static               { $this->name = $name; return $this; }
    public function setDescription(?string $d): static          { $this->description = $d; return $this; }
    public function setRepositoryUrl(?string $url): static      { $this->repositoryUrl = $url; return $this; }
    public function setTeam(?Team $team): static                { $this->team = $team; return $this; }

    public function addModule(Module $module): static
    {
        if (!$this->modules->contains($module)) {
            $this->modules->add($module);
            $module->setProject($this);
        }
        return $this;
    }

    public function removeModule(Module $module): static
    {
        $this->modules->removeElement($module);
        return $this;
    }
}
