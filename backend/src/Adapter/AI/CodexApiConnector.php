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
 * Connector implementation using the OpenAI Responses API for Codex-oriented models.
 */
class CodexApiConnector extends AbstractConnector
{
    use OpenAiModelDiscoveryTrait;

    private const API_URL = 'https://api.openai.com/v1/responses';
    private const DEFAULT_HEALTH_MODEL = 'gpt-5-mini';

    private Client $http;

    /**
     * Builds the HTTP client used to call the Codex-compatible OpenAI Responses API.
     */
    public function __construct(private readonly string $apiKey)
    {
        $this->http = new Client(['timeout' => 120]);
    }

    /**
     * Sends a prompt through the OpenAI Responses API and normalizes the reply.
     */
    public function sendRequest(ConnectorRequest $request, ConnectorConfig $config): ConnectorResponse
    {
        if ($this->apiKey === '') {
            throw new \RuntimeException('Codex API execution requires OPENAI_API_KEY.');
        }

        if ($config->model === null) {
            throw new \RuntimeException('Codex API requires a model to be specified in the agent configuration.');
        }

        $start = microtime(true);

        $response = $this->http->post(self::API_URL, [
            'headers' => [
                'Authorization' => sprintf('Bearer %s', $this->apiKey),
                'content-type' => 'application/json',
            ],
            'json' => [
                'model' => $config->model,
                'input' => $request->prompt->build(),
                'max_output_tokens' => $config->maxTokens,
                ...$config->extraParams,
            ],
        ]);

        $durationMs = (microtime(true) - $start) * 1000;
        $rawBody = (string) $response->getBody();
        $body = json_decode($rawBody, true);
        $usage = is_array($body['usage'] ?? null) ? $body['usage'] : [];
        $content = trim((string) ($body['output_text'] ?? ''));

        if ($content === '') {
            $content = $this->extractResponseOutputText($body);
        }

        return ConnectorResponse::fromApi($content, $usage, $durationMs, rawOutput: $rawBody);
    }

    /**
     * Returns the normalized OpenAI API credential status.
     */
    public function getAuthenticationStatus(): ConnectorAuthStatus
    {
        if ($this->apiKey === '') {
            return new ConnectorAuthStatus(
                required: true,
                authenticated: false,
                status: 'missing',
                method: 'api_key',
                summary: 'OpenAI API key is not configured.',
                error: 'OPENAI_API_KEY is empty.',
            );
        }

        return new ConnectorAuthStatus(
            required: true,
            authenticated: true,
            status: 'ok',
            method: 'api_key',
            summary: 'OpenAI API key is configured.',
        );
    }

    protected function connectorType(): ConnectorType
    {
        return ConnectorType::CodexApi;
    }

    protected function checkRuntime(): ConnectorHealthCheckResult
    {
        if ($this->apiKey === '') {
            return new ConnectorHealthCheckResult(
                name: 'runtime',
                status: 'degraded',
                summary: 'API key not configured (OPENAI_API_KEY).',
            );
        }

        try {
            $this->http->get('https://api.openai.com/v1/models', [
                'headers' => ['Authorization' => sprintf('Bearer %s', $this->apiKey)],
                'timeout' => 5,
            ]);

            return new ConnectorHealthCheckResult(
                name: 'runtime',
                status: 'ok',
                summary: 'OpenAI API is reachable.',
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
     * Reports whether this connector handles the Codex API runtime.
     */
    public function supportsConnector(ConnectorType $type): bool
    {
        return $type === ConnectorType::CodexApi;
    }

    /**
     * Declares that this adapter can discover models from the OpenAI model catalog.
     */
    public function supportsModelDiscovery(): bool
    {
        return true;
    }

    /**
     * Fetches the list of Codex-compatible models exposed by the OpenAI API.
     *
     * @return AgentModelInfo[]
     */
    public function discoverModels(): array
    {
        return $this->discoverOpenAiModels($this->http, $this->apiKey, 'openai');
    }

    /**
     * Extracts plain text from the Responses API output blocks when `output_text` is absent.
     *
     * @param array<string, mixed> $body
     */
    private function extractResponseOutputText(array $body): string
    {
        $parts = [];

        foreach ($body['output'] ?? [] as $output) {
            if (!is_array($output)) {
                continue;
            }

            foreach ($output['content'] ?? [] as $contentBlock) {
                if (is_array($contentBlock) && is_string($contentBlock['text'] ?? null)) {
                    $parts[] = $contentBlock['text'];
                }
            }
        }

        return trim(implode("\n\n", $parts));
    }

    protected function defaultHealthCheckConfig(): ConnectorConfig
    {
        return new ConnectorConfig(model: self::DEFAULT_HEALTH_MODEL, maxTokens: 64, timeout: 30);
    }
}
