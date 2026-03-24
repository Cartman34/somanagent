<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuditLog;
use App\Enum\AuditAction;
use Doctrine\ORM\EntityManagerInterface;

class AuditService
{
    public function __construct(private readonly EntityManagerInterface $em) {}

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
