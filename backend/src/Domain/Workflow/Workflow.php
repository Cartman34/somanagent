<?php

declare(strict_types=1);

namespace App\Domain\Workflow;

use Symfony\Component\Uid\Uuid;

/**
 * Un Workflow définit une séquence d'étapes à exécuter par des agents.
 * Il est défini en YAML et stocké en base + fichier.
 */
class Workflow
{
    private Uuid $id;
    private string $name;
    private ?string $description;
    private WorkflowTrigger $trigger;
    private bool $isDryRun = false;

    /** @var Step[] */
    private array $steps = [];

    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $name,
        WorkflowTrigger $trigger = WorkflowTrigger::Manual,
        ?string $description = null,
    ) {
        $this->id = Uuid::v7();
        $this->name = $name;
        $this->trigger = $trigger;
        $this->description = $description;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getDescription(): ?string { return $this->description; }
    public function getTrigger(): WorkflowTrigger { return $this->trigger; }
    public function getSteps(): array { return $this->steps; }
    public function isDryRun(): bool { return $this->isDryRun; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function addStep(Step $step): void
    {
        $this->steps[] = $step;
        $this->touch();
    }

    public function enableDryRun(): void
    {
        $this->isDryRun = true;
    }

    public function disableDryRun(): void
    {
        $this->isDryRun = false;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
