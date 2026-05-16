<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Service;

use SoManAgent\Script\Backlog\Agent\Enum\AgentRole;
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

        $prompt = $prompts[$role->value] ?? null;
        if ($prompt === null) {
            return null;
        }
        if (!is_string($prompt) || trim($prompt) === '') {
            throw new \RuntimeException(sprintf("Launch prompt for role '%s' must be a non-empty string.", $role->value));
        }

        return $prompt;
    }
}
