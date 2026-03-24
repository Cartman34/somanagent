<?php

declare(strict_types=1);

namespace App\Controller;

use App\Adapter\VCS\GitHubAdapter;
use App\Adapter\VCS\GitLabAdapter;
use App\Service\AgentPortRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/health')]
class HealthController extends AbstractController
{
    public function __construct(
        private readonly AgentPortRegistry $agentPortRegistry,
    ) {}

    #[Route('', name: 'health_check', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return $this->json([
            'status'  => 'ok',
            'version' => '1.0.0',
            'app'     => 'SoManAgent',
        ]);
    }

    #[Route('/connectors', name: 'health_connectors', methods: ['GET'])]
    public function connectors(): JsonResponse
    {
        $results = $this->agentPortRegistry->healthCheckAll();

        $allOk = !in_array(false, $results, true);

        return $this->json([
            'status'     => $allOk ? 'ok' : 'degraded',
            'connectors' => $results,
        ], $allOk ? 200 : 207);
    }
}
