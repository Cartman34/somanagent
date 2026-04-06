<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AgentActionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Represents a discrete action that an agent can perform, optionally tied to a role and a skill.
 */
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

    /**
     * Creates an action definition with its unique key, label, and optional description.
     */
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
    /**
     * Updates the modification timestamp before Doctrine persists an update.
     */
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** Returns the action identifier. */
    public function getId(): Uuid { return $this->id; }
    /** Returns the unique action key. */
    public function getKey(): string { return $this->key; }
    /** Returns the display label of the action. */
    public function getLabel(): string { return $this->label; }
    /** Returns the optional description of the action. */
    public function getDescription(): ?string { return $this->description; }
    /** Returns the role associated with this action. */
    public function getRole(): ?Role { return $this->role; }
    /** Returns the skill associated with this action. */
    public function getSkill(): ?Skill { return $this->skill; }
    /** Indicates whether the action is enabled. */
    public function isActive(): bool { return $this->isActive; }
    /** Returns when the action was created. */
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    /** Returns when the action was last updated. */
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** Updates the action label. */
    public function setLabel(string $label): static { $this->label = $label; return $this; }
    /** Updates the optional action description. */
    public function setDescription(?string $description): static { $this->description = $description; return $this; }
    /** Assigns or clears the role linked to this action. */
    public function setRole(?Role $role): static { $this->role = $role; return $this; }
    /** Assigns or clears the skill linked to this action. */
    public function setSkill(?Skill $skill): static { $this->skill = $skill; return $this; }
    /** Enables or disables the action. */
    public function setIsActive(bool $isActive): static { $this->isActive = $isActive; return $this; }
}
