<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Service;

use Sowapps\SoManAgent\Script\Backlog\Enum\SubmitMode;
use Symfony\Component\Yaml\Yaml;

/**
 * Reader for the local backlog configuration.
 *
 * Reads local/backlog/config.yaml. Requires the local file to exist at runtime
 * (created by `php scripts/setup.php install`). Provides typed accessors with
 * centralized hardcoded fallbacks for keys absent from an outdated local config.
 */
final class BacklogConfig
{
    /**
     * Path to the committed template, relative to the project root.
     */
    public const DIST_PATH = 'scripts/resources/backlog/config.yaml.dist';

    /**
     * Path to the local (gitignored) config, relative to the project root.
     */
    public const LOCAL_PATH = 'local/backlog/config.yaml';

    /**
     * @var int Fallback used when the local config does not define this key.
     */
    private const FALLBACK_MAX_CONCURRENT_WORKTREES = 5;

    /**
     * @var array<mixed>|null
     */
    private ?array $data = null;

    /**
     * @param string $projectRoot Absolute path to the project (or worktree) root.
     */
    public function __construct(private readonly string $projectRoot)
    {
    }

    /**
     * Returns the maximum number of developer worktrees allowed concurrently.
     */
    public function getMaxConcurrentWorktrees(): int
    {
        return (int) ($this->load()['backlog']['max_concurrent_worktrees'] ?? self::FALLBACK_MAX_CONCURRENT_WORKTREES);
    }

    /**
     * Returns the named scope map defined in the local config.
     *
     * Each key is a scope name (never `ALL`); each value is a list of directory prefixes
     * ending with `/`. Returns an empty array when the `scopes:` section is absent or
     * the local config file is missing.
     *
     * @return array<string, list<string>>
     */
    public function getScopes(): array
    {
        try {
            $raw = $this->load()['scopes'] ?? null;
        } catch (\RuntimeException) {
            return [];
        }

        if (!is_array($raw)) {
            return [];
        }

        $scopes = [];
        foreach ($raw as $name => $dirs) {
            if (!is_string($name) || $name === '' || !is_array($dirs)) {
                continue;
            }
            $normalized = [];
            foreach ($dirs as $dir) {
                if (!is_string($dir) || trim($dir) === '') {
                    continue;
                }
                $normalized[] = rtrim(trim($dir), '/') . '/';
            }
            $scopes[$name] = $normalized;
        }

        return $scopes;
    }

    /**
     * Returns the project-level submit policy.
     *
     * Fallback is SubmitMode::USER when the config is absent, unreadable, or carries an unknown value.
     */
    public function getWorkflowSubmit(): SubmitMode
    {
        try {
            $raw = $this->load()['workflow']['submit'] ?? null;
        } catch (\RuntimeException) {
            return SubmitMode::USER;
        }

        if ($raw !== null) {
            $mode = SubmitMode::tryFrom((string) $raw);
            if ($mode !== null) {
                return $mode;
            }
        }

        return SubmitMode::USER;
    }

    /**
     * @return array<mixed>
     *
     * @throws \RuntimeException When the local config is missing or invalid.
     */
    private function load(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        $localPath = $this->projectRoot . '/' . self::LOCAL_PATH;
        if (!is_file($localPath)) {
            throw new \RuntimeException(
                sprintf(
                    "Backlog local config not found: '%s'. Run 'php scripts/setup.php install' to create it.",
                    self::LOCAL_PATH,
                ),
            );
        }

        $parsed = Yaml::parseFile($localPath);
        if (!is_array($parsed)) {
            throw new \RuntimeException(
                sprintf("Backlog local config is invalid (expected a YAML mapping): '%s'.", self::LOCAL_PATH),
            );
        }

        $this->data = $parsed;

        return $this->data;
    }
}
