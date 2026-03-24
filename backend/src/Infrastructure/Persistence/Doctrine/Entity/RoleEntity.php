<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'roles')]
class RoleEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: TeamEntity::class, inversedBy: 'roles')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TeamEntity $team;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $skillSlug;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function getId(): Uuid { return $this->id; }
    public function getTeam(): TeamEntity { return $this->team; }
    public function setTeam(TeamEntity $t): void { $this->team = $t; }
    public function getName(): string { return $this->name; }
    public function setName(string $n): void { $this->name = $n; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $d): void { $this->description = $d; }
    public function getSkillSlug(): string { return $this->skillSlug; }
    public function setSkillSlug(string $s): void { $this->skillSlug = $s; }
}
