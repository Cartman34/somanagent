<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Controller;

use App\Enum\ConnectorType;
use App\Service\ConnectorRegistry;
use App\Service\LogService;
use App\ValueObject\ConnectorConfig;
use App\ValueObject\ConnectorRequest;
use App\ValueObject\Prompt;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Health check endpoints for monitoring application and connector status.
 */
#[Route('/api/health')]
class HealthController extends AbstractController
{
    /**
     * Initializes the controller with services used by the health endpoints.
     */
    public function __construct(
        private readonly ConnectorRegistry $connectorRegistry,
        private readonly LogService $logService,
    ) {}

    /**
     * Returns a simple application health payload.
     */
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
     *
     * Accepts an optional query parameter:
     *   - refresh — when truthy, triggers a deep check (real prompt sent per connector) and updates the cache.
     */
    #[Route('/connectors', name: 'health_connectors', methods: ['GET'])]
    public function connectors(Request $request): JsonResponse
    {
        $descriptors = $this->connectorRegistry->describeAll($request->query->getBoolean('refresh'));
        $allOk       = true;
        $connectors  = [];

        foreach ($descriptors as $descriptor) {
            $ok = $descriptor->isOverallHealthy();

            if (!$ok) {
                $allOk = false;
            }

            $connectors[$descriptor->connector->value] = [
                'label'      => $descriptor->connector->label(),
                'ok'         => $ok,
                'reason'     => $descriptor->overallReason(),
                'fixCommand' => $descriptor->overallFixCommand(),
                'authMethod' => $descriptor->authentication?->method,
                'checks'     => $descriptor->health->checks->toArray(),
            ];
        }

        if (!$allOk) {
            $failedConnectors = array_keys(array_filter($connectors, static fn (array $c): bool => !$c['ok']));
            $this->logService->record(
                source: 'infra',
                category: 'health',
                level: 'warning',
                title: '',
                message: '',
                options: [
                    'title_i18n' => [
                        'domain' => 'logs',
                        'key' => 'logs.health.degraded_connectors.title',
                    ],
                    'message_i18n' => [
                        'domain' => 'logs',
                        'key' => 'logs.health.degraded_connectors.message',
                        'parameters' => [
                            '%connectors%' => implode(', ', $failedConnectors),
                        ],
                    ],
                    'context' => [
                        'failed_connectors' => $failedConnectors,
                        'connectors' => $connectors,
                    ],
                    'raw_payload' => [
                        'status' => 'degraded',
                        'connectors' => $connectors,
                    ],
                ],
            );
        }

        return $this->json([
            'status'     => $allOk ? 'ok' : 'degraded',
            'connectors' => $connectors,
        ], $allOk ? 200 : 207);
    }

    /**
     * Returns Claude CLI auth health and records an infra warning when the runtime auth is missing.
     */
    #[Route('/claude-cli-auth', name: 'health_claude_cli_auth', methods: ['GET'])]
    public function claudeCliAuth(): JsonResponse
    {
        $status = $this->connectorRegistry->getFor(ConnectorType::ClaudeCli)->getAuthenticationStatus();

        if (!$status->isHealthy()) {
            $this->logService->record(
                source: 'infra',
                category: 'auth',
                level: 'warning',
                title: '',
                message: '',
                options: [
                    'title_i18n' => [
                        'domain' => 'logs',
                        'key' => 'logs.auth.claude_cli_unavailable.title',
                    ],
                    'message_i18n' => [
                        'domain' => 'logs',
                        'key' => 'logs.auth.claude_cli_unavailable.message',
                    ],
                    'context' => $status->toArray(),
                    'raw_payload' => $status->toArray(),
                ],
            );
        }

        return $this->json([
            'status' => $status->isHealthy() ? 'ok' : 'degraded',
            'loggedIn' => $status->authenticated,
            'authMethod' => $status->method,
            'apiProvider' => $status->metadata['apiProvider'] ?? null,
            'raw' => $status->metadata['raw'] ?? null,
            'error' => $status->error,
        ], $status->isHealthy() ? 200 : 207);
    }

    /**
     * Returns connector authentication health when the connector exposes a dedicated runtime auth check.
     */
    #[Route('/connectors/{connector}/auth', name: 'health_connector_auth', methods: ['GET'])]
    public function connectorAuth(string $connector): JsonResponse
    {
        try {
            $connectorType = ConnectorType::from($connector);
        } catch (\ValueError) {
            return $this->json([
                'status' => 'degraded',
                'error' => sprintf('Unknown connector "%s".', $connector),
            ], 404);
        }

        $status = $this->connectorRegistry->getFor($connectorType)->getAuthenticationStatus();

        if (!$status->isHealthy()) {
            $messageI18n = $connectorType === ConnectorType::ClaudeCli
                ? [
                    'domain' => 'logs',
                    'key' => 'logs.auth.claude_cli_unavailable.message',
                    'parameters' => [
                        '%connector%' => $connectorType->label(),
                    ],
                ]
                : [
                    'domain' => 'logs',
                    'key' => 'logs.auth.connector_auth_unavailable.message',
                    'parameters' => [
                        '%connector%' => $connectorType->label(),
                    ],
                ];
            $titleI18n = $connectorType === ConnectorType::ClaudeCli
                ? [
                    'domain' => 'logs',
                    'key' => 'logs.auth.claude_cli_unavailable.title',
                ]
                : [
                    'domain' => 'logs',
                    'key' => 'logs.auth.connector_auth_unavailable.title',
                ];

            $this->logService->record(
                source: 'infra',
                category: 'auth',
                level: 'warning',
                title: '',
                message: '',
                options: [
                    'title_i18n' => $titleI18n,
                    'message_i18n' => $messageI18n,
                    'context' => [
                        'connector' => $connectorType->value,
                        ...$status->toArray(),
                    ],
                    'raw_payload' => [
                        'connector' => $connectorType->value,
                        ...$status->toArray(),
                    ],
                ],
            );
        }

        return $this->json([
            'status' => $status->isHealthy() ? 'ok' : 'degraded',
            ...$status->toArray(),
        ], $status->isHealthy() ? 200 : 207);
    }

    /**
     * Sends a minimal test prompt through the connector and returns the result.
     *
     * Accepts optional query parameters:
     *   - model   — override the model (required for connectors with no built-in default)
     *   - message — override the test prompt (default: "Reply with exactly the word: PONG")
     *
     * This endpoint exercises the real sendRequest() path inside FPM, allowing comparison
     * with the CLI path tested by somanagent:connector:test.
     */
    #[Route('/connectors/{connector}/test', name: 'health_connector_test', methods: ['POST'])]
    public function connectorTest(string $connector, Request $request): JsonResponse
    {
        try {
            $connectorType = ConnectorType::from($connector);
        } catch (\ValueError) {
            return $this->json(['ok' => false, 'error' => sprintf('Unknown connector "%s".', $connector)], 404);
        }

        $model = trim((string) $request->query->get('model', ''));

        $message = trim((string) ($request->query->get('message') ?: 'Say OK'));
        $connector = $this->connectorRegistry->getFor($connectorType);
        $requestPayload = ConnectorRequest::fromPrompt(Prompt::create('', $message), ConnectorRequest::DEFAULT_WORKING_DIRECTORY);
        $config  = new ConnectorConfig(model: $model, maxTokens: 64, timeout: 30);

        try {
            $response = $connector->sendRequest($requestPayload, $config);

            return $this->json([
                'ok'           => true,
                'connector'    => $connectorType->value,
                'model'        => $model,
                'response'     => mb_substr($response->content, 0, 300),
                'inputTokens'  => $response->inputTokens,
                'outputTokens' => $response->outputTokens,
                'durationMs'   => (int) $response->durationMs,
                'sessionId'    => $response->sessionId,
            ]);
        } catch (\Throwable $e) {
            return $this->json([
                'ok'        => false,
                'connector' => $connectorType->value,
                'model'     => $model,
                'error'     => $e->getMessage(),
            ], 207);
        }
    }
}
