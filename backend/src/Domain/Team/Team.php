<?php

declare(strict_types=1);

namespace App\Domain\Team;

use Symfony\Component\Uid\Uuid;

/**
 * Une équipe regroupe des rôles assignés à des agents.
 * Une équipe est générique et peut être assignée à plusieurs modules.
 */
class Team
{
    private Uuid $id;
    private string $name;
    private ?string $description;

    /** @var Role[] */
    private array $roles = [];

    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;

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
    public function getRoles(): array { return $this->roles; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function addRole(Role $role): void
    {
        $this->roles[] = $role;
        $this->touch();
    }

    public function removeRole(Uuid $roleId): void
    {
        $this->roles = array_filter(
            $this->roles,
            fn(Role $r) => !$r->getId()->equals($roleId)
        );
        $this->touch();
    }

    public function rename(string $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
