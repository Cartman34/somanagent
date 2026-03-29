<?php

declare(strict_types=1);

namespace App\Controller;

use App\Adapter\VCS\GitHubAdapter;
use App\Adapter\VCS\GitLabAdapter;
use App\Service\AgentPortRegistry;
use App\Service\ClaudeCliAuthService;
use App\Service\LogService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/health')]
class HealthController extends AbstractController
{
    public function __construct(
        private readonly AgentPortRegistry $agentPortRegistry,
        private readonly ClaudeCliAuthService $claudeCliAuthService,
        private readonly LogService $logService,
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

    /**
     * Returns connector health and records an infra warning when at least one connector is degraded.
     */
    #[Route('/connectors', name: 'health_connectors', methods: ['GET'])]
    public function connectors(): JsonResponse
    {
        $results = $this->agentPortRegistry->healthCheckAll();

        $allOk = !in_array(false, $results, true);

        if (!$allOk) {
            $failedConnectors = array_keys(array_filter($results, static fn (bool $ok): bool => $ok === false));
            $this->logService->record(
                source: 'infra',
                category: 'health',
                level: 'warning',
                // Stored in DB for the in-app log UI, so the human-facing message stays in French.
                title: 'Connecteurs dégradés',
                // Stored in DB for the in-app log UI, so the human-facing message stays in French.
                message: sprintf('Un ou plusieurs connecteurs sont indisponibles: %s', implode(', ', $failedConnectors)),
                options: [
                    'context' => [
                        'failed_connectors' => $failedConnectors,
                        'connectors' => $results,
                    ],
                    'raw_payload' => [
                        'status' => 'degraded',
                        'connectors' => $results,
                    ],
                ],
            );
        }

        return $this->json([
            'status'     => $allOk ? 'ok' : 'degraded',
            'connectors' => $results,
        ], $allOk ? 200 : 207);
    }

    /**
     * Returns Claude CLI auth health and records an infra warning when the runtime auth is missing.
     */
    #[Route('/claude-cli-auth', name: 'health_claude_cli_auth', methods: ['GET'])]
    public function claudeCliAuth(): JsonResponse
    {
        $status = $this->claudeCliAuthService->getStatus();

        if (($status['loggedIn'] ?? false) !== true) {
            $this->logService->record(
                source: 'infra',
                category: 'auth',
                level: 'warning',
                // Stored in DB for the in-app log UI, so the human-facing message stays in French.
                title: 'Authentification Claude CLI indisponible',
                // Stored in DB for the in-app log UI, so the human-facing message stays in French.
                message: 'La CLI Claude n’est pas authentifiée dans l’environnement runtime.',
                options: [
                    'context' => $status,
                    'raw_payload' => $status,
                ],
            );
        }

        return $this->json([
            'status' => $status['loggedIn'] ? 'ok' : 'degraded',
            ...$status,
        ], $status['loggedIn'] ? 200 : 207);
    }
}
