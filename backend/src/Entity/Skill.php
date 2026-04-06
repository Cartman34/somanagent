<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SkillSource;
use App\Repository\SkillRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Uid\Uuid;

/**
 * Represents a skill definition (e.g. from SKILL.md files) that can be imported or custom-created.
 */
#[ORM\Entity(repositoryClass: SkillRepository::class)]
#[ORM\Table(name: 'skill')]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['slug'])]
class Skill
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    /**
     * Unique skill identifier (for example: "code-reviewer", "backend-dev").
     */
    #[ORM\Column(length: 255, unique: true)]
    private string $slug;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(enumType: SkillSource::class)]
    private SkillSource $source;

    /**
     * Import source reference (for example: "anthropics/skills"); null for custom skills.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $originalSource = null;

    /**
     * Full contents of the SKILL.md file.
     */
    #[ORM\Column(type: 'text')]
    private string $content;

    /**
     * Relative path from the skills/ directory (for example: "imported/code-reviewer/SKILL.md").
     */
    #[ORM\Column(length: 512)]
    private string $filePath;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * Creates a skill definition from its slug, source metadata, and SKILL.md contents.
     */
    public function __construct(
        string      $slug,
        string      $name,
        string      $content,
        string      $filePath,
        SkillSource $source,
        ?string     $description    = null,
        ?string     $originalSource = null,
    ) {
        $this->id             = Uuid::v7();
        $this->slug           = $slug;
        $this->name           = $name;
        $this->content        = $content;
        $this->filePath       = $filePath;
        $this->source         = $source;
        $this->description    = $description;
        $this->originalSource = $originalSource;
        $this->createdAt      = new \DateTimeImmutable();
        $this->updatedAt      = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    /**
     * Updates the modification timestamp before Doctrine persists an update.
     */
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** Returns the skill identifier. */
    public function getId(): Uuid               { return $this->id; }
    /** Returns the unique skill slug. */
    public function getSlug(): string           { return $this->slug; }
    /** Returns the display name of the skill. */
    public function getName(): string           { return $this->name; }
    /** Returns the optional skill description. */
    public function getDescription(): ?string   { return $this->description; }
    /** Returns the source type of the skill. */
    public function getSource(): SkillSource    { return $this->source; }
    /** Returns the original import source reference, if any. */
    public function getOriginalSource(): ?string { return $this->originalSource; }
    /** Returns the full SKILL.md content. */
    public function getContent(): string        { return $this->content; }
    /** Returns the relative file path of the skill definition. */
    public function getFilePath(): string       { return $this->filePath; }
    /** Returns when the skill was created. */
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    /** Returns when the skill was last updated. */
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** Updates the skill display name. */
    public function setName(string $name): static           { $this->name = $name; return $this; }
    /** Updates the skill description. */
    public function setDescription(?string $d): static      { $this->description = $d; return $this; }
    /** Replaces the full SKILL.md content. */
    public function setContent(string $content): static     { $this->content = $content; return $this; }
    /** Updates the relative file path of the skill definition. */
    public function setFilePath(string $path): static       { $this->filePath = $path; return $this; }
}
