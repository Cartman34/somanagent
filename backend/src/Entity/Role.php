<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RoleRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: RoleRepository::class)]
#[ORM\Table(name: 'role')]
class Role
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Team::class, inversedBy: 'roles')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Team $team;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /**
     * Slug du skill associé à ce rôle (ex: "code-reviewer").
     * Nullable : un rôle peut être défini sans skill assigné.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $skillSlug = null;

    public function __construct(Team $team, string $name, ?string $description = null, ?string $skillSlug = null)
    {
        $this->id          = Uuid::v7();
        $this->team        = $team;
        $this->name        = $name;
        $this->description = $description;
        $this->skillSlug   = $skillSlug;
    }

    public function getId(): Uuid            { return $this->id; }
    public function getTeam(): Team          { return $this->team; }
    public function getName(): string        { return $this->name; }
    public function getDescription(): ?string { return $this->description; }
    public function getSkillSlug(): ?string  { return $this->skillSlug; }

    public function setTeam(Team $team): static               { $this->team = $team; return $this; }
    public function setName(string $name): static             { $this->name = $name; return $this; }
    public function setDescription(?string $d): static        { $this->description = $d; return $this; }
    public function setSkillSlug(?string $slug): static       { $this->skillSlug = $slug; return $this; }
}
