<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Domain\Agent\Agent;
use App\Domain\Skill\Skill;
use App\Domain\Workflow\Step;

/**
 * Port de communication avec les agents IA.
 * Chaque connecteur IA (Claude API, Claude CLI, etc.) implémente cette interface.
 */
interface AgentPort
{
    /**
     * Envoie un prompt à l'agent et retourne sa réponse.
     */
    public function sendPrompt(Agent $agent, Skill $skill, string $prompt, array $context = []): string;

    /**
     * Exécute une étape de workflow et retourne l'output structuré.
     */
    public function executeStep(Agent $agent, Step $step, array $input): array;

    /**
     * Vérifie que le connecteur est correctement configuré et accessible.
     */
    public function healthCheck(): bool;

    /**
     * Retourne le nom du connecteur (ex: "claude-api", "claude-cli").
     */
    public function getName(): string;
}
