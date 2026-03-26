<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TeamRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

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
     * Agents membres de cette équipe (many-to-many).
     * Un agent peut appartenir à plusieurs équipes.
     *
     * @var Collection<int, Agent>
     */
    #[ORM\ManyToMany(targetEntity: Agent::class, inversedBy: 'teams')]
    #[ORM\JoinTable(name: 'agent_team')]
    private Collection $agents;

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
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid                      { return $this->id; }
    public function getName(): string                  { return $this->name; }
    public function getDescription(): ?string          { return $this->description; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** @return Collection<int, Agent> */
    public function getAgents(): Collection { return $this->agents; }

    public function setName(string $name): static      { $this->name = $name; return $this; }
    public function setDescription(?string $d): static { $this->description = $d; return $this; }

    public function addAgent(Agent $agent): static
    {
        if (!$this->agents->contains($agent)) {
            $this->agents->add($agent);
            $agent->addTeam($this);
        }
        return $this;
    }

    public function removeAgent(Agent $agent): static
    {
        if ($this->agents->removeElement($agent)) {
            $agent->removeTeam($this);
        }
        return $this;
    }
}
