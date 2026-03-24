<?php

declare(strict_types=1);

namespace App\Infrastructure\AI\Claude;

use App\Application\Port\AgentPort;
use App\Domain\Agent\Agent;
use App\Domain\Skill\Skill;
use App\Domain\Workflow\Step;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Connecteur Claude via l'API REST Anthropic.
 * Implémente AgentPort pour s'intégrer dans l'architecture hexagonale.
 */
class ClaudeApiAdapter implements AgentPort
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey,
    ) {}

    public function sendPrompt(Agent $agent, Skill $skill, string $prompt, array $context = []): string
    {
        $systemPrompt = $this->buildSystemPrompt($skill, $context);

        $response = $this->httpClient->request('POST', self::API_URL, [
            'headers' => [
                'x-api-key'         => $this->apiKey,
                'anthropic-version' => self::API_VERSION,
                'content-type'      => 'application/json',
            ],
            'json' => [
                'model'      => $agent->getConfig()->model,
                'max_tokens' => $agent->getConfig()->maxTokens,
                'system'     => $systemPrompt,
                'messages'   => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ],
        ]);

        $data = $response->toArray();

        return $data['content'][0]['text'] ?? '';
    }

    public function executeStep(Agent $agent, Step $step, array $input): array
    {
        $prompt = $this->buildStepPrompt($step, $input);

        // Pour l'instant, on retourne le texte brut dans un tableau structuré.
        // Les futures versions pourront parser le JSON structuré de la réponse.
        $text = $this->sendPrompt($agent, new \App\Domain\Skill\Skill(
            slug: $step->getSkillSlug(),
            name: $step->getSkillSlug(),
            content: '',
        ), $prompt, $input);

        return [
            'output_key' => $step->getOutputKey(),
            'content'    => $text,
            'step_key'   => $step->getStepKey(),
        ];
    }

    public function healthCheck(): bool
    {
        try {
            $response = $this->httpClient->request('GET', 'https://api.anthropic.com/v1/models', [
                'headers' => [
                    'x-api-key'         => $this->apiKey,
                    'anthropic-version' => self::API_VERSION,
                ],
            ]);
            return $response->getStatusCode() === 200;
        } catch (\Throwable) {
            return false;
        }
    }

    public function getName(): string
    {
        return 'claude-api';
    }

    private function buildSystemPrompt(Skill $skill, array $context): string
    {
        $system = $skill->getContent();

        if (!empty($context)) {
            $system .= "\n\n## Contexte\n";
            foreach ($context as $key => $value) {
                $system .= "- **{$key}**: {$value}\n";
            }
        }

        return $system;
    }

    private function buildStepPrompt(Step $step, array $input): string
    {
        $prompt = "## Étape : {$step->getName()}\n\n";
        $prompt .= "**Rôle attendu :** {$step->getRoleId()}\n\n";

        if (!empty($input)) {
            $prompt .= "## Données d'entrée\n\n";
            foreach ($input as $key => $value) {
                $prompt .= "### {$key}\n{$value}\n\n";
            }
        }

        if ($step->getCondition()) {
            $prompt .= "**Condition :** Cette étape s'exécute si : `{$step->getCondition()}`\n\n";
        }

        $prompt .= "Effectue la tâche demandée et retourne un résultat structuré.";

        return $prompt;
    }
}
