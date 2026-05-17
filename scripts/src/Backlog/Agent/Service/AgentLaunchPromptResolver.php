<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Service;

use SoManAgent\Script\Backlog\Agent\Enum\AgentRole;
use SoManAgent\Script\Backlog\Agent\Model\LaunchDecision;
use SoManAgent\Script\Backlog\Model\BacklogBoard;
use Symfony\Component\Yaml\Yaml;

/**
 * Resolves the natural-language prompt sent to a client after an automatic backlog pick.
 */
final class AgentLaunchPromptResolver
{
    /**
     * Absolute path of the launch prompt YAML resource.
     */
    private string $promptPath;

    /**
     * @param string $promptPath Absolute path of scripts/resources/backlog-agent/launch-prompts.yaml
     */
    public function __construct(string $promptPath)
    {
        $this->promptPath = $promptPath;
    }

    /**
     * Returns the configured prompt for the given role, or null when the role has no auto-pick prompt.
     */
    public function resolve(AgentRole $role): ?string
    {
        return $this->loadPrompt($role->value);
    }

    /**
     * Returns a {@see LaunchDecision} for the given role and current entry stage.
     *
     * Stage semantics:
     * - null            : no active entry — auto-pick from todo (developer) or no pick (reviewer)
     * - development     : entry is in active development
     * - review          : entry is submitted and awaiting a reviewer
     * - reviewing       : entry is being actively reviewed
     * - rejected        : entry was rejected by the reviewer
     * - approved        : entry was approved; the launcher must handle pre-launch rebase
     *
     * @param AgentRole $role
     * @param string|null $stage Normalized stage value from BacklogBoard::STAGE_* or null
     * @return LaunchDecision
     */
    public function resolveStageDecision(AgentRole $role, ?string $stage): LaunchDecision
    {
        return match ($role) {
            AgentRole::DEVELOPER => $this->resolveForDeveloper($stage),
            AgentRole::REVIEWER  => $this->resolveForReviewer($stage),
            default              => LaunchDecision::prompt(null),
        };
    }

    /**
     * Returns the conflict prompt to send to the agent when an approved entry rebase stopped on conflict.
     *
     * @return string
     */
    public function resolveConflictPrompt(): string
    {
        return $this->requirePrompt('developer_conflict');
    }

    /**
     * Resolves the decision for the developer role based on the current entry stage.
     */
    private function resolveForDeveloper(?string $stage): LaunchDecision
    {
        return match ($stage) {
            null => LaunchDecision::prompt($this->requirePrompt('developer')),
            BacklogBoard::STAGE_IN_PROGRESS => LaunchDecision::prompt($this->requirePrompt('developer_resume')),
            BacklogBoard::STAGE_REJECTED => LaunchDecision::prompt($this->requirePrompt('developer_rework')),
            BacklogBoard::STAGE_APPROVED => LaunchDecision::launcherHandled(),
            BacklogBoard::STAGE_IN_REVIEW => LaunchDecision::refuse(
                'Tâche soumise pour review, en attente d\'un reviewer — rien à faire côté developer pour l\'instant.'
            ),
            BacklogBoard::STAGE_REVIEWING => LaunchDecision::refuse(
                'Review en cours, attends le retour du reviewer.'
            ),
            default => LaunchDecision::refuse('Tâche déjà mergée, aucune action attendue.'),
        };
    }

    /**
     * Resolves the decision for the reviewer role based on the current entry stage.
     */
    private function resolveForReviewer(?string $stage): LaunchDecision
    {
        return match ($stage) {
            BacklogBoard::STAGE_IN_REVIEW => LaunchDecision::prompt($this->requirePrompt('reviewer')),
            BacklogBoard::STAGE_REVIEWING => LaunchDecision::prompt($this->requirePrompt('reviewer_resume')),
            null => LaunchDecision::refuse('Rien à reviewer dans le todo.'),
            BacklogBoard::STAGE_IN_PROGRESS => LaunchDecision::refuse(
                'Tâche pas encore soumise, attends que le dev passe en review.'
            ),
            BacklogBoard::STAGE_REJECTED => LaunchDecision::refuse(
                'Review déjà rejetée, le dev doit retravailler.'
            ),
            BacklogBoard::STAGE_APPROVED => LaunchDecision::refuse(
                'Tâche approuvée, le merge est manuel via `user-merge` — pas le rôle du reviewer.'
            ),
            default => LaunchDecision::refuse('Tâche mergée.'),
        };
    }

    /**
     * @return array<string, string>
     */
    private function loadPrompts(): array
    {
        if (!is_file($this->promptPath)) {
            throw new \RuntimeException(sprintf('Launch prompt file not found: %s', $this->promptPath));
        }

        $data = Yaml::parseFile($this->promptPath);
        if (!is_array($data)) {
            throw new \RuntimeException(sprintf('Launch prompt file must contain a YAML object: %s', $this->promptPath));
        }

        $prompts = $data['prompts'] ?? null;
        if (!is_array($prompts)) {
            throw new \RuntimeException('Launch prompt file must define a prompts object.');
        }

        return $prompts;
    }

    /**
     * Returns the prompt for the given key, or null when the key is absent.
     */
    private function loadPrompt(string $key): ?string
    {
        $prompts = $this->loadPrompts();
        $prompt = $prompts[$key] ?? null;
        if ($prompt === null) {
            return null;
        }
        if (trim($prompt) === '') {
            throw new \RuntimeException(sprintf("Launch prompt for key '%s' must be a non-empty string.", $key));
        }

        return $prompt;
    }

    /**
     * Returns the prompt for the given key and throws when absent.
     */
    private function requirePrompt(string $key): string
    {
        $prompt = $this->loadPrompt($key);
        if ($prompt === null) {
            throw new \RuntimeException(sprintf("Launch prompt file is missing required key '%s'.", $key));
        }

        return $prompt;
    }
}
