<?php

declare(strict_types=1);

namespace App\Domain\Project;

use Symfony\Component\Uid\Uuid;

/**
 * Un Module représente un logiciel distinct au sein d'un Projet.
 * Ex: API PHP, Client Android, Client iOS, Frontend React...
 */
class Module
{
    private Uuid $id;
    private string $name;
    private ?string $description;
    private ?string $techStack;        // ex: "PHP 8.3 / Symfony", "React / TypeScript"
    private ?string $repositoryUrl;   // URL du repo Git associé
    private ?string $repositoryBranch; // Branche principale
    private ModuleStatus $status;
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $name,
        ?string $description = null,
        ?string $techStack = null,
        ?string $repositoryUrl = null,
        ?string $repositoryBranch = 'main',
    ) {
        $this->id = Uuid::v7();
        $this->name = $name;
        $this->description = $description;
        $this->techStack = $techStack;
        $this->repositoryUrl = $repositoryUrl;
        $this->repositoryBranch = $repositoryBranch;
        $this->status = ModuleStatus::Active;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getDescription(): ?string { return $this->description; }
    public function getTechStack(): ?string { return $this->techStack; }
    public function getRepositoryUrl(): ?string { return $this->repositoryUrl; }
    public function getRepositoryBranch(): ?string { return $this->repositoryBranch; }
    public function getStatus(): ModuleStatus { return $this->status; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function linkRepository(string $url, string $branch = 'main'): void
    {
        $this->repositoryUrl = $url;
        $this->repositoryBranch = $branch;
        $this->touch();
    }

    public function archive(): void
    {
        $this->status = ModuleStatus::Archived;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
