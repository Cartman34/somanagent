<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ConnectorType;
use App\Repository\AgentRepository;
use App\ValueObject\AgentConfig;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Represents an AI agent configured with a connector, model settings, and an optional specialization role.
 */
#[ORM\Entity(repositoryClass: AgentRepository::class)]
#[ORM\Table(name: 'agent')]
#[ORM\HasLifecycleCallbacks]
class Agent
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(enumType: ConnectorType::class)]
    private ConnectorType $connector;

    /**
     * Serialized JSON agent configuration: model, max_tokens, temperature, timeout, and extra options.
     */
    #[ORM\Column(type: 'json')]
    private array $config;

    /**
     * Specialization role assigned to the agent after creation.
     * Each agent has at most one role describing its area of expertise.
     */
    #[ORM\ManyToOne(targetEntity: Role::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Role $role = null;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * Teams the agent belongs to through a many-to-many relationship.
     *
     * @var Collection<int, Team>
     */
    #[ORM\ManyToMany(targetEntity: Team::class, mappedBy: 'agents')]
    private Collection $teams;

    /**
     * Creates an agent with its connector settings and optional description.
     */
    public function __construct(
        string        $name,
        ConnectorType $connector,
        AgentConfig   $config,
        ?string       $description = null,
    ) {
        $this->id          = Uuid::v7();
        $this->name        = $name;
        $this->connector   = $connector;
        $this->config      = $config->toArray();
        $this->description = $description;
        $this->createdAt   = new \DateTimeImmutable();
        $this->updatedAt   = new \DateTimeImmutable();
        $this->teams       = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    /**
     * Updates the modification timestamp before Doctrine persists an update.
     */
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** Returns the agent identifier. */
    public function getId(): Uuid                      { return $this->id; }
    /** Returns the display name of the agent. */
    public function getName(): string                  { return $this->name; }
    /** Returns the optional description of the agent. */
    public function getDescription(): ?string          { return $this->description; }
    /** Returns the connector used by the agent. */
    public function getConnector(): ConnectorType      { return $this->connector; }
    /** Returns the specialization role assigned to the agent. */
    public function getRole(): ?Role                   { return $this->role; }
    /** Indicates whether the agent is currently active. */
    public function isActive(): bool                   { return $this->isActive; }
    /** Returns when the agent was created. */
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    /** Returns when the agent was last updated. */
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** @return Collection<int, Team> */
    public function getTeams(): Collection { return $this->teams; }

    /**
     * Returns the normalized agent configuration value object.
     */
    public function getAgentConfig(): AgentConfig
    {
        return AgentConfig::fromArray($this->config);
    }

    /** Updates the agent name. */
    public function setName(string $name): static              { $this->name = $name; return $this; }
    /** Updates the agent description. */
    public function setDescription(?string $d): static         { $this->description = $d; return $this; }
    /** Updates the connector used by the agent. */
    public function setConnector(ConnectorType $c): static     { $this->connector = $c; return $this; }
    /** Assigns or clears the specialization role. */
    public function setRole(?Role $role): static               { $this->role = $role; return $this; }
    /** Enables or disables the agent. */
    public function setIsActive(bool $active): static          { $this->isActive = $active; return $this; }

    /**
     * Replaces the stored connector configuration.
     */
    public function setAgentConfig(AgentConfig $config): static
    {
        $this->config = $config->toArray();
        return $this;
    }

    /**
     * Adds the agent to a team association when missing.
     */
    public function addTeam(Team $team): static
    {
        if (!$this->teams->contains($team)) {
            $this->teams->add($team);
        }
        return $this;
    }

    /**
     * Removes the agent from a team association.
     */
    public function removeTeam(Team $team): static
    {
        $this->teams->removeElement($team);
        return $this;
    }
}
