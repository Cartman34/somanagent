<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Controller;

use App\Repository\AuditLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/audit')]
class AuditController extends AbstractController
{
    public function __construct(private readonly AuditLogRepository $auditLogRepository) {}

    #[Route('', name: 'audit_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page  = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 25)));

        $total = $this->auditLogRepository->count([]);
        $logs  = $this->auditLogRepository->findBy(
            [],
            ['createdAt' => 'DESC'],
            $limit,
            ($page - 1) * $limit,
        );

        return $this->json([
            'data'  => array_map(fn($log) => [
                'id'         => (string) $log->getId(),
                'action'     => $log->getAction()->value,
                'entityType' => $log->getEntityType(),
                'entityId'   => $log->getEntityId(),
                'data'       => $log->getData(),
                'createdAt'  => $log->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ], $logs),
            'total' => $total,
            'page'  => $page,
            'limit' => $limit,
        ]);
    }
}
