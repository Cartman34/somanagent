<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Service;

use App\Entity\AgentTaskExecution;
use App\Entity\AgentTaskExecutionAttempt;
use App\Entity\AuditLog;
use App\Entity\ChatMessage;
use App\Entity\LogEvent;
use App\Entity\LogOccurrence;
use App\Entity\TicketLog;
use App\Entity\TokenUsage;
use App\Enum\AuditAction;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Thin wrapper around Doctrine's EntityManager for the four standard persistence operations.
 *
 * Automatically records an {@see AuditLog} entry after each mutating operation when an
 * {@see AuditAction} is provided. Excluded entity classes are listed in {@see self::AUDIT_EXCLUDED}.
 *
 * Use {@see self::persist()} when you need to stage an entity without flushing (e.g. batching
 * multiple entities before a single {@see self::flush()} call).
 *
 * Note: {@see AuditService} intentionally keeps its own EntityManager injection to avoid
 * a circular dependency with this service.
 */
final class EntityService
{
    /**
     * Entity classes excluded from audit.
     *
     * - {@see AuditLog}: recursion guard
     * - {@see LogEvent}, {@see LogOccurrence}: runtime monitoring, too high-volume
     * - {@see TicketLog}: log entity itself — auditing a log is redundant
     * - {@see ChatMessage}: high-volume communication record already persisted
     * - {@see TokenUsage}: operational LLM metric, not a business action
     * - {@see AgentTaskExecution}, {@see AgentTaskExecutionAttempt}: execution lifecycle already self-documented
     *
     * @var list<class-string>
     */
    private const AUDIT_EXCLUDED = [
        AuditLog::class,
        LogEvent::class,
        LogOccurrence::class,
        TicketLog::class,
        ChatMessage::class,
        TokenUsage::class,
        AgentTaskExecution::class,
        AgentTaskExecutionAttempt::class,
    ];

    /**
     * @param EntityManagerInterface $em    Doctrine entity manager.
     * @param AuditService           $audit Audit writer; called after each mutating operation.
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditService           $audit,
    ) {}

    /**
     * Stages an entity for persistence without flushing.
     *
     * Use this when batching several entities before a single {@see self::flush()} call.
     * No audit entry is written; pass an {@see AuditAction} to {@see self::create()} or
     * {@see self::update()} instead if an immediate flush + audit is acceptable.
     */
    public function persist(object $entity): void
    {
        $this->em->persist($entity);
    }

    /**
     * Persists and immediately flushes a new entity, then writes an optional audit entry.
     *
     * @param array<string, mixed> $data Contextual snapshot for the audit entry.
     */
    public function create(object $entity, ?AuditAction $action = null, array $data = []): void
    {
        $this->em->persist($entity);
        $this->em->flush();
        $this->audit($entity, $action, $data);
    }

    /**
     * Flushes pending changes for an existing managed entity, then writes an optional audit entry.
     *
     * @param array<string, mixed> $data Contextual snapshot for the audit entry.
     */
    public function update(object $entity, ?AuditAction $action = null, array $data = []): void
    {
        $this->em->flush();
        $this->audit($entity, $action, $data);
    }

    /**
     * Removes an entity and flushes, then writes an optional audit entry.
     *
     * The entity ID is captured before removal so it remains available in the audit entry.
     *
     * @param array<string, mixed> $data Contextual snapshot for the audit entry.
     */
    public function delete(object $entity, ?AuditAction $action = null, array $data = []): void
    {
        $entityId = $this->extractId($entity);
        $this->em->remove($entity);
        $this->em->flush();
        $this->audit($entity, $action, $data, $entityId);
    }

    /**
     * Flushes all pending changes without writing any audit entry.
     */
    public function flush(): void
    {
        $this->em->flush();
    }

    /**
     * Writes an audit entry if an action is provided and the entity is not in {@see self::AUDIT_EXCLUDED}.
     *
     * @param array<string, mixed> $data
     */
    private function audit(object $entity, ?AuditAction $action, array $data, ?string $entityId = null): void
    {
        if ($action === null || in_array($entity::class, self::AUDIT_EXCLUDED, true)) {
            return;
        }

        $this->audit->log(
            $action,
            $this->extractType($entity),
            $entityId ?? $this->extractId($entity),
            $data !== [] ? $data : null,
        );
    }

    /**
     * Derives the entity type label from the short class name (e.g. 'App\Entity\Project' → 'Project').
     */
    private function extractType(object $entity): string
    {
        $class = $entity::class;
        $pos   = strrpos($class, '\\');

        return $pos !== false ? substr($class, $pos + 1) : $class;
    }

    /**
     * Returns the entity UUID string via getId(), or null if the method does not exist.
     */
    private function extractId(object $entity): ?string
    {
        return method_exists($entity, 'getId') ? (string) $entity->getId() : null;
    }
}
