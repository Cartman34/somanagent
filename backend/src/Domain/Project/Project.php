<?php

declare(strict_types=1);

namespace App\Domain\Project;

use Symfony\Component\Uid\Uuid;

class Project
{
    private Uuid $id;
    private string $name;
    private ?string $description;
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;

    /** @var Module[] */
    private array $modules = [];

    public function __construct(string $name, ?string $description = null)
    {
        $this->id = Uuid::v7();
        $this->name = $name;
        $this->description = $description;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getDescription(): ?string { return $this->description; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function getModules(): array { return $this->modules; }

    public function rename(string $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    public function updateDescription(?string $description): void
    {
        $this->description = $description;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
