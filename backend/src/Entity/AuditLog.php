<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AuditAction;
use App\Repository\AuditLogRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Immutable cross-cutting audit record for application-level actions.
 *
 * Captures who did what to which entity. Distinct from:
 * - {@see \App\Entity\TicketLog}: narrative history scoped to a ticket
 * - LogEvent / LogOccurrence: runtime monitoring (Monolog)
 *
 * Write via {@see \App\Service\AuditService::log()} only — never instantiate directly outside tests.
 */
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

    /** Short class name of the affected entity, e.g. 'Project', 'Ticket', 'TicketTask'. */
    #[ORM\Column(length: 100)]
    private string $entityType;

    /** RFC 4122 UUID string of the affected entity, or null when the entity no longer exists. */
    #[ORM\Column(length: 36, nullable: true)]
    private ?string $entityId = null;

    /** Contextual snapshot: before/after values, relevant parameters. Shape varies by action. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $data = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param string      $entityType Short class name of the affected entity (e.g. 'Project')
     * @param string|null $entityId   RFC 4122 UUID string of the affected entity
     * @param array<string, mixed>|null $data Contextual snapshot for this action
     */
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

    /**
     * @see AuditLog::$id
     */
    public function getId(): Uuid               { return $this->id; }

    /**
     * @see AuditLog::$action
     */
    public function getAction(): AuditAction    { return $this->action; }

    /**
     * @see AuditLog::$entityType
     */
    public function getEntityType(): string     { return $this->entityType; }

    /**
     * @see AuditLog::$entityId
     */
    public function getEntityId(): ?string      { return $this->entityId; }

    /**
     * @see AuditLog::$data
     */
    public function getData(): ?array           { return $this->data; }

    /**
     * @see AuditLog::$createdAt
     */
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
