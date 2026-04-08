<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Adapter\AI;

use App\Enum\ConnectorType;
use App\ValueObject\ConnectorAuthStatus;
use App\ValueObject\ConnectorConfig;
use App\ValueObject\ConnectorHealthCheckResult;
use App\ValueObject\ConnectorRequest;
use App\ValueObject\ConnectorResponse;
use App\ValueObject\AgentModelInfo;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Connector implementation using the Claude API (Anthropic REST API).
 */
class ClaudeApiConnector extends AbstractConnector
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';
    private const DEFAULT_HEALTH_MODEL = 'claude-sonnet-4-5';

    private Client $http;

    /**
     * Initializes the API client with the configured Anthropic API key.
     */
    public function __construct(private readonly string $apiKey)
    {
        $this->http = new Client(['timeout' => 120]);
    }

    /**
     * Sends a prompt to the Anthropic Messages API and normalizes the response payload.
     */
    public function sendRequest(ConnectorRequest $request, ConnectorConfig $config): ConnectorResponse
    {
        if ($this->apiKey === '') {
            throw new \RuntimeException('Claude API execution requires CLAUDE_API_KEY.');
        }

        if ($config->model === null) {
            throw new \RuntimeException('Claude API requires a model to be specified in the connector configuration.');
        }

        $start = microtime(true);

        $response = $this->http->post(self::API_URL, [
            'headers' => [
                'x-api-key'         => $this->apiKey,
                'anthropic-version' => self::API_VERSION,
                'content-type'      => 'application/json',
            ],
            'json' => [
                'model'      => $config->model,
                'max_tokens' => $config->maxTokens,
                'messages'   => [
                    ['role' => 'user', 'content' => $request->prompt->build()],
                ],
                ...$config->extraParams,
            ],
        ]);

        $durationMs = (microtime(true) - $start) * 1000;
        $rawBody    = (string) $response->getBody();
        $body       = json_decode($rawBody, true);
        $content    = $body['content'][0]['text'] ?? '';
        $usage      = $body['usage'] ?? [];

        return ConnectorResponse::fromApi($content, $usage, $durationMs, rawOutput: $rawBody);
    }

    /**
     * Returns the normalized API credential status included in connector health.
     */
    public function getAuthenticationStatus(): ConnectorAuthStatus
    {
        if ($this->apiKey === '') {
            return new ConnectorAuthStatus(
                required: true,
                authenticated: false,
                status: 'missing',
                method: 'api_key',
                summary: 'Claude API key is not configured.',
                error: 'CLAUDE_API_KEY is empty.',
            );
        }

        return new ConnectorAuthStatus(
            required: true,
            authenticated: true,
            status: 'ok',
            method: 'api_key',
            summary: 'Claude API key is configured.',
        );
    }

    protected function connectorType(): ConnectorType
    {
        return ConnectorType::ClaudeApi;
    }

    protected function checkRuntime(): ConnectorHealthCheckResult
    {
        if ($this->apiKey === '') {
            return new ConnectorHealthCheckResult(
                name: 'runtime',
                status: 'degraded',
                summary: 'API key not configured (CLAUDE_API_KEY).',
            );
        }

        try {
            $this->http->get('https://api.anthropic.com', [
                'headers' => ['x-api-key' => $this->apiKey],
                'timeout' => 5,
            ]);

            return new ConnectorHealthCheckResult(
                name: 'runtime',
                status: 'ok',
                summary: 'Anthropic API is reachable.',
            );
        } catch (GuzzleException $exception) {
            return new ConnectorHealthCheckResult(
                name: 'runtime',
                status: 'degraded',
                summary: $exception->getMessage(),
            );
        }
    }

    /**
     * Reports whether this connector handles the Claude API runtime.
     */
    public function supportsConnector(ConnectorType $type): bool
    {
        return $type === ConnectorType::ClaudeApi;
    }

    /**
     * Indicates that Claude API model discovery is not implemented in this adapter yet.
     */
    public function supportsModelDiscovery(): bool
    {
        return false;
    }

    /**
     * @return AgentModelInfo[]
     */
    public function discoverModels(): array
    {
        return [];
    }

    protected function defaultHealthCheckConfig(): ConnectorConfig
    {
        return new ConnectorConfig(model: self::DEFAULT_HEALTH_MODEL, maxTokens: 64, timeout: 30);
    }
}
