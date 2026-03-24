<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'skills')]
#[ORM\HasLifecycleCallbacks]
class SkillEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: 'string', length: 255, unique: true)]
    private string $slug;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'text')]
    private string $content;  // Corps Markdown du SKILL.md

    /**
     * Métadonnées du frontmatter YAML (hors name/description).
     * Stocké en JSONB PostgreSQL.
     */
    #[ORM\Column(type: 'json')]
    private array $metadata = [];

    #[ORM\Column(type: 'string', length: 50)]
    private string $source;  // "imported" | "custom"

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $originRef = null;  // Ex: "anthropics/code-reviewer"

    #[ORM\Column(type: 'string', length: 512)]
    private string $localPath;  // Chemin relatif du SKILL.md

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void { $this->updatedAt = new \DateTimeImmutable(); }

    public function getId(): Uuid { return $this->id; }
    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $s): void { $this->slug = $s; }
    public function getName(): string { return $this->name; }
    public function setName(string $n): void { $this->name = $n; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $d): void { $this->description = $d; }
    public function getContent(): string { return $this->content; }
    public function setContent(string $c): void { $this->content = $c; }
    public function getMetadata(): array { return $this->metadata; }
    public function setMetadata(array $m): void { $this->metadata = $m; }
    public function getSource(): string { return $this->source; }
    public function setSource(string $s): void { $this->source = $s; }
    public function getOriginRef(): ?string { return $this->originRef; }
    public function setOriginRef(?string $r): void { $this->originRef = $r; }
    public function getLocalPath(): string { return $this->localPath; }
    public function setLocalPath(string $p): void { $this->localPath = $p; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
