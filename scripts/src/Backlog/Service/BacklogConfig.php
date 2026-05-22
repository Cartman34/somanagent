<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Service;

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
    /** Path to the committed template, relative to the project root. */
    public const DIST_PATH = 'scripts/resources/backlog/config.yaml.dist';

    /** Path to the local (gitignored) config, relative to the project root. */
    public const LOCAL_PATH = 'local/backlog/config.yaml';

    /** @var int Fallback used when the local config does not define this key. */
    private const FALLBACK_MAX_CONCURRENT_WORKTREES = 5;

    /** @var array<mixed>|null */
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
     * @return array<mixed>
     *
     * @throws \RuntimeException When the dist template or local config is missing.
     */
    private function load(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        $distPath = $this->projectRoot . '/' . self::DIST_PATH;
        if (!is_file($distPath)) {
            throw new \RuntimeException(
                sprintf(
                    "Backlog config template not found: '%s'. The repository may be corrupted.",
                    self::DIST_PATH,
                ),
            );
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
