<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RoleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: RoleRepository::class)]
#[ORM\Table(name: 'role')]
class Role
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 100, unique: true)]
    private string $slug;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /**
     * Skills associés à ce rôle (many-to-many).
     * Un rôle peut requérir plusieurs compétences.
     *
     * @var Collection<int, Skill>
     */
    #[ORM\ManyToMany(targetEntity: Skill::class)]
    #[ORM\JoinTable(name: 'role_skill')]
    private Collection $skills;

    public function __construct(string $slug, string $name, ?string $description = null)
    {
        $this->id          = Uuid::v7();
        $this->slug        = $slug;
        $this->name        = $name;
        $this->description = $description;
        $this->skills      = new ArrayCollection();
    }

    public function getId(): Uuid             { return $this->id; }
    public function getSlug(): string         { return $this->slug; }
    public function getName(): string         { return $this->name; }
    public function getDescription(): ?string { return $this->description; }

    /** @return Collection<int, Skill> */
    public function getSkills(): Collection { return $this->skills; }

    public function setSlug(string $slug): static             { $this->slug = $slug; return $this; }
    public function setName(string $name): static             { $this->name = $name; return $this; }
    public function setDescription(?string $d): static        { $this->description = $d; return $this; }

    public function addSkill(Skill $skill): static
    {
        if (!$this->skills->contains($skill)) {
            $this->skills->add($skill);
        }
        return $this;
    }

    public function removeSkill(Skill $skill): static
    {
        $this->skills->removeElement($skill);
        return $this;
    }
}
