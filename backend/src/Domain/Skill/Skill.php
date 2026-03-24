<?php

declare(strict_types=1);

namespace App\Domain\Skill;

use Symfony\Component\Uid\Uuid;

/**
 * Un Skill correspond à un fichier SKILL.md (format skills.sh).
 * Il peut être importé depuis le registry skills.sh ou créé localement.
 */
class Skill
{
    private Uuid $id;
    private string $slug;          // Ex: "code-reviewer", "backend-dev"
    private string $name;
    private ?string $description;
    private string $content;       // Contenu Markdown du SKILL.md (corps)
    private array $metadata;       // Frontmatter YAML parsé
    private SkillSource $source;   // imported | custom
    private ?string $originRef;    // Ex: "anthropics/code-reviewer" si importé
    private string $localPath;     // Chemin local du fichier SKILL.md
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $slug,
        string $name,
        string $content,
        array $metadata = [],
        SkillSource $source = SkillSource::Custom,
        ?string $originRef = null,
        string $localPath = '',
        ?string $description = null,
    ) {
        $this->id = Uuid::v7();
        $this->slug = $slug;
        $this->name = $name;
        $this->content = $content;
        $this->metadata = $metadata;
        $this->source = $source;
        $this->originRef = $originRef;
        $this->localPath = $localPath;
        $this->description = $description;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getSlug(): string { return $this->slug; }
    public function getName(): string { return $this->name; }
    public function getDescription(): ?string { return $this->description; }
    public function getContent(): string { return $this->content; }
    public function getMetadata(): array { return $this->metadata; }
    public function getSource(): SkillSource { return $this->source; }
    public function getOriginRef(): ?string { return $this->originRef; }
    public function getLocalPath(): string { return $this->localPath; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /**
     * Retourne le contenu complet du fichier SKILL.md (frontmatter + corps).
     */
    public function toSkillMdContent(): string
    {
        $frontmatter = "---\n";
        $frontmatter .= "name: {$this->slug}\n";
        $frontmatter .= "description: " . ($this->description ?? $this->name) . "\n";
        foreach ($this->metadata as $key => $value) {
            if (!in_array($key, ['name', 'description'])) {
                $frontmatter .= "{$key}: {$value}\n";
            }
        }
        $frontmatter .= "---\n\n";

        return $frontmatter . $this->content;
    }

    public function updateContent(string $content): void
    {
        $this->content = $content;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
