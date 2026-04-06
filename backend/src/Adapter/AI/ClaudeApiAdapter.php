<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Adapter\AI;

use App\Enum\ConnectorType;
use App\Port\AgentPort;
use App\ValueObject\AgentConfig;
use App\ValueObject\AgentResponse;
use App\ValueObject\Prompt;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * AgentPort implementation using the Claude API (Anthropic REST API).
 */
class ClaudeApiAdapter implements AgentPort
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';

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
    public function sendPrompt(Prompt $prompt, AgentConfig $config): AgentResponse
    {
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
                    ['role' => 'user', 'content' => $prompt->build()],
                ],
                ...$config->extraParams,
            ],
        ]);

        $durationMs = (microtime(true) - $start) * 1000;
        $body       = json_decode((string) $response->getBody(), true);
        $content    = $body['content'][0]['text'] ?? '';
        $usage      = $body['usage'] ?? [];

        return AgentResponse::fromApi($content, $usage, $durationMs);
    }

    /**
     * Checks whether the Anthropic API is reachable with the configured credentials.
     */
    public function healthCheck(): bool
    {
        try {
            $this->http->get('https://api.anthropic.com', [
                'headers' => ['x-api-key' => $this->apiKey],
                'timeout' => 5,
            ]);
            return true;
        } catch (GuzzleException) {
            return false;
        }
    }

    /**
     * Indicates whether this adapter handles the Claude API connector type.
     */
    public function supportsConnector(ConnectorType $type): bool
    {
        return $type === ConnectorType::ClaudeApi;
    }
}
