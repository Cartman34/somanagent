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
     * Sérialisé en JSON : model, max_tokens, temperature, timeout, extra.
     */
    #[ORM\Column(type: 'json')]
    private array $config;

    /**
     * Rôle de spécialisation de l'agent (immuable après création).
     * Un agent a un seul rôle qui définit sa compétence.
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
     * Équipes auxquelles cet agent est affecté (many-to-many).
     *
     * @var Collection<int, Team>
     */
    #[ORM\ManyToMany(targetEntity: Team::class, mappedBy: 'agents')]
    private Collection $teams;

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
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid                      { return $this->id; }
    public function getName(): string                  { return $this->name; }
    public function getDescription(): ?string          { return $this->description; }
    public function getConnector(): ConnectorType      { return $this->connector; }
    public function getRole(): ?Role                   { return $this->role; }
    public function isActive(): bool                   { return $this->isActive; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** @return Collection<int, Team> */
    public function getTeams(): Collection { return $this->teams; }

    public function getAgentConfig(): AgentConfig
    {
        return AgentConfig::fromArray($this->config);
    }

    public function setName(string $name): static              { $this->name = $name; return $this; }
    public function setDescription(?string $d): static         { $this->description = $d; return $this; }
    public function setConnector(ConnectorType $c): static     { $this->connector = $c; return $this; }
    public function setRole(?Role $role): static               { $this->role = $role; return $this; }
    public function setIsActive(bool $active): static          { $this->isActive = $active; return $this; }

    public function setAgentConfig(AgentConfig $config): static
    {
        $this->config = $config->toArray();
        return $this;
    }

    public function addTeam(Team $team): static
    {
        if (!$this->teams->contains($team)) {
            $this->teams->add($team);
        }
        return $this;
    }

    public function removeTeam(Team $team): static
    {
        $this->teams->removeElement($team);
        return $this;
    }
}
