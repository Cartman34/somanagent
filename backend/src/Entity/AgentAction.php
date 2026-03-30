<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AgentActionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AgentActionRepository::class)]
#[ORM\Table(name: 'agent_action')]
#[ORM\UniqueConstraint(name: 'uniq_agent_action_key', columns: ['action_key'])]
#[ORM\HasLifecycleCallbacks]
class AgentAction
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(name: 'action_key', length: 255, unique: true)]
    private string $key;

    #[ORM\Column(length: 255)]
    private string $label;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToOne(targetEntity: Role::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Role $role = null;

    #[ORM\ManyToOne(targetEntity: Skill::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Skill $skill = null;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $key, string $label, ?string $description = null)
    {
        $this->id = Uuid::v7();
        $this->key = $key;
        $this->label = $label;
        $this->description = $description;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getKey(): string { return $this->key; }
    public function getLabel(): string { return $this->label; }
    public function getDescription(): ?string { return $this->description; }
    public function getRole(): ?Role { return $this->role; }
    public function getSkill(): ?Skill { return $this->skill; }
    public function isActive(): bool { return $this->isActive; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function setLabel(string $label): static { $this->label = $label; return $this; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }
    public function setRole(?Role $role): static { $this->role = $role; return $this; }
    public function setSkill(?Skill $skill): static { $this->skill = $skill; return $this; }
    public function setIsActive(bool $isActive): static { $this->isActive = $isActive; return $this; }
}
