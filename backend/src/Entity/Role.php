<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RoleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Represents a specialization role that an agent or task can be assigned to, grouping required skills.
 */
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
     * Skills assigned to this role through a many-to-many relationship.
     * A single role can require multiple skills.
     *
     * @var Collection<int, Skill>
     */
    #[ORM\ManyToMany(targetEntity: Skill::class)]
    #[ORM\JoinTable(name: 'role_skill')]
    private Collection $skills;

    /**
     * Creates a role with its unique slug, display name, and optional description.
     */
    public function __construct(string $slug, string $name, ?string $description = null)
    {
        $this->id          = Uuid::v7();
        $this->slug        = $slug;
        $this->name        = $name;
        $this->description = $description;
        $this->skills      = new ArrayCollection();
    }

    /** Returns the role identifier. */
    public function getId(): Uuid             { return $this->id; }
    /** Returns the unique role slug. */
    public function getSlug(): string         { return $this->slug; }
    /** Returns the display name of the role. */
    public function getName(): string         { return $this->name; }
    /** Returns the optional role description. */
    public function getDescription(): ?string { return $this->description; }

    /** @return Collection<int, Skill> */
    public function getSkills(): Collection { return $this->skills; }

    /** Updates the unique role slug. */
    public function setSlug(string $slug): static             { $this->slug = $slug; return $this; }
    /** Updates the display name of the role. */
    public function setName(string $name): static             { $this->name = $name; return $this; }
    /** Updates the optional role description. */
    public function setDescription(?string $d): static        { $this->description = $d; return $this; }

    /**
     * Adds a skill to the role if it is not already linked.
     */
    public function addSkill(Skill $skill): static
    {
        if (!$this->skills->contains($skill)) {
            $this->skills->add($skill);
        }
        return $this;
    }

    /**
     * Removes a skill from the role association.
     */
    public function removeSkill(Skill $skill): static
    {
        $this->skills->removeElement($skill);
        return $this;
    }
}
