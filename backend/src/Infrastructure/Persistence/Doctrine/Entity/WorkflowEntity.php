<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'workflows')]
#[ORM\HasLifecycleCallbacks]
class WorkflowEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'string', length: 50)]
    private string $trigger = 'manual';

    /**
     * Définition complète des étapes en JSONB.
     * Évite une table steps séparée pour la Phase 1.
     * Une table dédiée sera créée en Phase 5.
     */
    #[ORM\Column(type: 'json')]
    private array $steps = [];

    #[ORM\Column(type: 'boolean')]
    private bool $isDryRun = false;

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
    public function getName(): string { return $this->name; }
    public function setName(string $n): void { $this->name = $n; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $d): void { $this->description = $d; }
    public function getTrigger(): string { return $this->trigger; }
    public function setTrigger(string $t): void { $this->trigger = $t; }
    public function getSteps(): array { return $this->steps; }
    public function setSteps(array $s): void { $this->steps = $s; }
    public function isDryRun(): bool { return $this->isDryRun; }
    public function setDryRun(bool $d): void { $this->isDryRun = $d; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
