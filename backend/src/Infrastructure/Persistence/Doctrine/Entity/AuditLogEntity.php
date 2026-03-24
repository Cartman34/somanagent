<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'audit_logs')]
class AuditLogEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: 'string', length: 100)]
    private string $action;  // Ex: "workflow.started"

    #[ORM\Column(type: 'string', length: 100)]
    private string $entityType;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $entityId = null;

    #[ORM\Column(type: 'json')]
    private array $context = [];

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $result = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $occurredAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getAction(): string { return $this->action; }
    public function setAction(string $a): void { $this->action = $a; }
    public function getEntityType(): string { return $this->entityType; }
    public function setEntityType(string $e): void { $this->entityType = $e; }
    public function getEntityId(): ?string { return $this->entityId; }
    public function setEntityId(?string $e): void { $this->entityId = $e; }
    public function getContext(): array { return $this->context; }
    public function setContext(array $c): void { $this->context = $c; }
    public function getResult(): ?string { return $this->result; }
    public function setResult(?string $r): void { $this->result = $r; }
    public function getErrorMessage(): ?string { return $this->errorMessage; }
    public function setErrorMessage(?string $m): void { $this->errorMessage = $m; }
    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }
}
