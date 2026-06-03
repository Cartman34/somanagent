<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Adapter\AI;

use Sowapps\SoManAgent\Port\ConnectorInterface;
use Sowapps\SoManAgent\ValueObject\ConnectorConfig;
use Sowapps\SoManAgent\ValueObject\ConnectorHealthReport;
use Sowapps\SoManAgent\ValueObject\ConnectorHealthChecks;
use Sowapps\SoManAgent\ValueObject\ConnectorHealthCheckResult;
use Sowapps\SoManAgent\ValueObject\ConnectorAuthStatus;
use Sowapps\SoManAgent\ValueObject\ConnectorRequest;
use Sowapps\SoManAgent\ValueObject\Prompt;
use Sowapps\SoManAgent\Enum\ConnectorType;
/**
 * Shared connector base exposing one normalized request API and a common health battery.
 */
abstract class AbstractConnector implements ConnectorInterface
{
    final public function healthCheck(): bool
    {
        return $this->checkHealth()->isHealthy();
    }

    final public function healthCheckReason(): ?string
    {
        return $this->checkHealth()->overallSummary();
    }

    final public function checkHealth(?ConnectorConfig $config = null, bool $deep = false): ConnectorHealthReport
    {
        return new ConnectorHealthReport(
            connector: $this->connectorType(),
            checks: new ConnectorHealthChecks(
                runtime: $this->checkRuntime(),
                auth: $this->getAuthenticationStatus()->toHealthCheckResult(),
                promptTest: $deep
                    ? $this->checkPromptExecution($config ?? $this->defaultHealthCheckConfig())
                    : new ConnectorHealthCheckResult(
                        name: 'prompt_test',
                        status: 'skipped',
                        summary: 'Run somanagent:health or use the dashboard refresh button to test.',
                    ),
                models: $this->checkModelDiscovery(),
            ),
        );
    }

    /**
     * Returns the connector auth status used by the shared health battery.
     */
    public function getAuthenticationStatus(): ConnectorAuthStatus
    {
        return ConnectorAuthStatus::skipped();
    }

    protected function defaultHealthCheckConfig(): ConnectorConfig
    {
        return new ConnectorConfig(maxTokens: 64, timeout: 30);
    }

    protected function healthCheckRequest(): ConnectorRequest
    {
        return ConnectorRequest::fromPrompt(Prompt::create('', 'Say OK'), ConnectorRequest::DEFAULT_WORKING_DIRECTORY);
    }

    abstract protected function connectorType(): ConnectorType;

    abstract protected function checkRuntime(): ConnectorHealthCheckResult;

    protected function checkPromptExecution(ConnectorConfig $config): ConnectorHealthCheckResult
    {
        try {
            $response = $this->sendRequest($this->healthCheckRequest(), $config);

            if ($response->isEmpty()) {
                return new ConnectorHealthCheckResult(
                    name: 'prompt_test',
                    status: 'degraded',
                    summary: 'Prompt execution returned an empty response.',
                );
            }

            return new ConnectorHealthCheckResult(
                name: 'prompt_test',
                status: 'ok',
                summary: sprintf('Prompt test succeeded in %d ms.', (int) $response->durationMs),
                details: [
                    'inputTokens' => $response->inputTokens,
                    'outputTokens' => $response->outputTokens,
                    'durationMs' => (int) $response->durationMs,
                    'sessionId' => $response->sessionId,
                ],
            );
        } catch (\Throwable $throwable) {
            return new ConnectorHealthCheckResult(
                name: 'prompt_test',
                status: 'degraded',
                summary: $throwable->getMessage(),
            );
        }
    }

    protected function checkModelDiscovery(): ConnectorHealthCheckResult
    {
        if (!$this->supportsModelDiscovery()) {
            return new ConnectorHealthCheckResult(
                name: 'models',
                status: 'skipped',
                summary: 'This connector does not expose runtime model discovery.',
            );
        }

        try {
            $models = $this->discoverModels();

            return new ConnectorHealthCheckResult(
                name: 'models',
                status: 'ok',
                summary: sprintf('%d model(s) discovered.', count($models)),
                details: ['count' => count($models)],
            );
        } catch (\Throwable $throwable) {
            return new ConnectorHealthCheckResult(
                name: 'models',
                status: 'degraded',
                summary: $throwable->getMessage(),
            );
        }
    }
}
