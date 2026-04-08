<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Adapter\AI;

use App\ValueObject\AgentModelInfo;
use GuzzleHttp\Client;

/**
 * Shared OpenAI model discovery helpers for API and CLI adapters.
 */
trait OpenAiModelDiscoveryTrait
{
    /**
     * Retrieves the OpenAI model catalog and converts the supported entries into normalized descriptors.
     *
     * @return AgentModelInfo[]
     */
    private function discoverOpenAiModels(Client $httpClient, string $apiKey, string $provider): array
    {
        if ($apiKey === '') {
            throw new \RuntimeException('OpenAI model discovery requires OPENAI_API_KEY.');
        }

        $response = $httpClient->get('https://api.openai.com/v1/models', [
            'headers' => [
                'Authorization' => sprintf('Bearer %s', $apiKey),
                'content-type' => 'application/json',
            ],
        ]);

        $body = json_decode((string) $response->getBody(), true);
        $models = [];

        foreach ($body['data'] ?? [] as $model) {
            if (!is_array($model) || !is_string($model['id'] ?? null)) {
                continue;
            }

            $modelId = $model['id'];

            if (!$this->supportsOpenAiModelId($modelId)) {
                continue;
            }

            $models[] = new AgentModelInfo(
                id: $modelId,
                label: $modelId,
                provider: $provider,
                family: $this->resolveOpenAiFamily($modelId),
                description: $this->resolveOpenAiDescription($modelId),
                status: 'active',
                metadata: [
                    'created' => $model['created'] ?? null,
                    'ownedBy' => $model['owned_by'] ?? null,
                ],
            );
        }

        return $models;
    }

    /**
     * Filters the OpenAI catalog down to model ids accepted by the current connector policy.
     */
    private function supportsOpenAiModelId(string $modelId): bool
    {
        $modelId = strtolower($modelId);

        return str_contains($modelId, 'codex')
            || str_contains($modelId, 'gpt-5')
            || str_contains($modelId, 'o3')
            || str_contains($modelId, 'o4')
            || str_contains($modelId, 'gpt-4.1');
    }

    /**
     * Derives a stable family label from the OpenAI model id for downstream display and grouping.
     */
    private function resolveOpenAiFamily(string $modelId): string
    {
        $normalizedModelId = strtolower($modelId);

        foreach (['codex', 'gpt-5', 'gpt-4.1', 'o4', 'o3'] as $family) {
            if (str_contains($normalizedModelId, $family)) {
                return $family;
            }
        }

        return 'openai';
    }

    /**
     * Builds a short human-readable description from the OpenAI model naming pattern.
     */
    private function resolveOpenAiDescription(string $modelId): string
    {
        $normalizedModelId = strtolower($modelId);

        if (str_contains($normalizedModelId, 'codex')) {
            return 'Coding-focused OpenAI model.';
        }

        if (str_contains($normalizedModelId, 'gpt-5')) {
            return 'Reasoning-capable OpenAI model suited for coding workflows.';
        }

        if (str_contains($normalizedModelId, 'mini')) {
            return 'Lower-latency OpenAI model variant.';
        }

        if (str_contains($normalizedModelId, 'nano')) {
            return 'Lowest-cost OpenAI model variant.';
        }

        return 'OpenAI model available to this connector.';
    }
}
