<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuditLog;
use App\Enum\AuditAction;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Single entry point for writing {@see AuditLog} records.
 *
 * Call this after a successful persist/flush so the audit entry is consistent
 * with the actual database state.
 */
final class AuditService
{
    /**
     * @param EntityManagerInterface $em Used to persist and flush each audit entry immediately.
     */
    public function __construct(private readonly EntityManagerInterface $em) {}

    /**
     * Persists an audit entry for a completed action on an entity.
     *
     * @param string                    $entityType Short class name of the affected entity (e.g. 'Project', 'TicketTask')
     * @param string|null               $entityId   RFC 4122 UUID string of the affected entity
     * @param array<string, mixed>|null $data       Contextual snapshot relevant to this action (before/after values, parameters)
     */
    public function log(
        AuditAction $action,
        string      $entityType,
        ?string     $entityId = null,
        ?array      $data     = null,
    ): void {
        $log = new AuditLog($action, $entityType, $entityId, $data);
        $this->em->persist($log);
        $this->em->flush();
    }
}
