<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use Symfony\Component\Uid\Uuid;

/**
 * Entrée dans le journal d'audit.
 * Toute action importante est tracée (lancement workflow, import skill, push Git...).
 */
class AuditLog
{
    private Uuid $id;
    private AuditAction $action;
    private string $entityType;   // Ex: "workflow", "skill", "project"
    private ?string $entityId;
    private array $context;        // Données contextuelles (JSON)
    private ?string $result;       // "success" | "error" | "dry_run"
    private ?string $errorMessage;
    private \DateTimeImmutable $occurredAt;

    public function __construct(
        AuditAction $action,
        string $entityType,
        ?string $entityId = null,
        array $context = [],
        ?string $result = null,
        ?string $errorMessage = null,
    ) {
        $this->id = Uuid::v7();
        $this->action = $action;
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->context = $context;
        $this->result = $result;
        $this->errorMessage = $errorMessage;
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getAction(): AuditAction { return $this->action; }
    public function getEntityType(): string { return $this->entityType; }
    public function getEntityId(): ?string { return $this->entityId; }
    public function getContext(): array { return $this->context; }
    public function getResult(): ?string { return $this->result; }
    public function getErrorMessage(): ?string { return $this->errorMessage; }
    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }
}
