<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\DevEnv;

/**
 * Queries available package versions from a specific source.
 *
 * Implementations include the live system querier (apt-cache, npm, GitHub API)
 * and a fake implementation used in tests.
 */
interface SourceQuerierInterface
{
    /**
     * Returns all available versions for the given package from the given source.
     *
     * Returns an empty list when the source is unavailable or the package is not found.
     *
     * @param string $installer Installer type: apt, npm-global, github-release
     * @param string $source Source identifier (e.g. 'default', 'ppa:ondrej/php', 'npm', GitHub URL)
     * @param string $package Package name
     * @return list<string>
     */
    public function queryVersions(string $installer, string $source, string $package): array;
}
