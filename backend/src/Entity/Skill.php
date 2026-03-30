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
     * Identifiant unique du skill (ex: "code-reviewer", "backend-dev").
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
     * Source d'import (ex: "anthropics/skills"). Null si custom.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $originalSource = null;

    /**
     * Contenu complet du fichier SKILL.md.
     */
    #[ORM\Column(type: 'text')]
    private string $content;

    /**
     * Chemin relatif depuis le dossier skills/ (ex: "imported/code-reviewer/SKILL.md").
     */
    #[ORM\Column(length: 512)]
    private string $filePath;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

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
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid               { return $this->id; }
    public function getSlug(): string           { return $this->slug; }
    public function getName(): string           { return $this->name; }
    public function getDescription(): ?string   { return $this->description; }
    public function getSource(): SkillSource    { return $this->source; }
    public function getOriginalSource(): ?string { return $this->originalSource; }
    public function getContent(): string        { return $this->content; }
    public function getFilePath(): string       { return $this->filePath; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function setName(string $name): static           { $this->name = $name; return $this; }
    public function setDescription(?string $d): static      { $this->description = $d; return $this; }
    public function setContent(string $content): static     { $this->content = $content; return $this; }
    public function setFilePath(string $path): static       { $this->filePath = $path; return $this; }
}
