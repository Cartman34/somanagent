<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TeamRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Represents a team grouping agents that can be assigned to projects.
 */
#[ORM\Entity(repositoryClass: TeamRepository::class)]
#[ORM\Table(name: 'team')]
#[ORM\HasLifecycleCallbacks]
class Team
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * Agents assigned to this team through a many-to-many relationship.
     * An agent can belong to multiple teams.
     *
     * @var Collection<int, Agent>
     */
    #[ORM\ManyToMany(targetEntity: Agent::class, inversedBy: 'teams')]
    #[ORM\JoinTable(name: 'agent_team')]
    private Collection $agents;

    /**
     * Creates a team with its display name and optional description.
     */
    public function __construct(string $name, ?string $description = null)
    {
        $this->id          = Uuid::v7();
        $this->name        = $name;
        $this->description = $description;
        $this->createdAt   = new \DateTimeImmutable();
        $this->updatedAt   = new \DateTimeImmutable();
        $this->agents      = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    /**
     * Updates the modification timestamp before Doctrine persists an update.
     */
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** Returns the team identifier. */
    public function getId(): Uuid                      { return $this->id; }
    /** Returns the display name of the team. */
    public function getName(): string                  { return $this->name; }
    /** Returns the optional team description. */
    public function getDescription(): ?string          { return $this->description; }
    /** Returns when the team was created. */
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    /** Returns when the team was last updated. */
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** @return Collection<int, Agent> */
    public function getAgents(): Collection { return $this->agents; }

    /** Updates the display name of the team. */
    public function setName(string $name): static      { $this->name = $name; return $this; }
    /** Updates the team description. */
    public function setDescription(?string $d): static { $this->description = $d; return $this; }

    /**
     * Adds an agent to the team if it is not already present.
     */
    public function addAgent(Agent $agent): static
    {
        if (!$this->agents->contains($agent)) {
            $this->agents->add($agent);
            $agent->addTeam($this);
        }
        return $this;
    }

    /**
     * Removes an agent from the team association.
     */
    public function removeAgent(Agent $agent): static
    {
        if ($this->agents->removeElement($agent)) {
            $agent->removeTeam($this);
        }
        return $this;
    }
}
