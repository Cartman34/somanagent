<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AuditAction;
use App\Repository\AuditLogRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
#[ORM\Table(name: 'audit_log')]
#[ORM\Index(columns: ['action'], name: 'idx_audit_action')]
#[ORM\Index(columns: ['entity_type', 'entity_id'], name: 'idx_audit_entity')]
#[ORM\Index(columns: ['created_at'], name: 'idx_audit_created_at')]
class AuditLog
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(enumType: AuditAction::class)]
    private AuditAction $action;

    #[ORM\Column(length: 100)]
    private string $entityType;

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $entityId = null;

    /**
     * Données contextuelles de l'action (snapshot avant/après, paramètres...).
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $data = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        AuditAction $action,
        string      $entityType,
        ?string     $entityId = null,
        ?array      $data     = null,
    ) {
        $this->id         = Uuid::v7();
        $this->action     = $action;
        $this->entityType = $entityType;
        $this->entityId   = $entityId;
        $this->data       = $data;
        $this->createdAt  = new \DateTimeImmutable();
    }

    public function getId(): Uuid               { return $this->id; }
    public function getAction(): AuditAction    { return $this->action; }
    public function getEntityType(): string     { return $this->entityType; }
    public function getEntityId(): ?string      { return $this->entityId; }
    public function getData(): ?array           { return $this->data; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
