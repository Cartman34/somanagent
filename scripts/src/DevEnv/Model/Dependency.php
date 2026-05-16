<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\DevEnv\Model;

/**
 * Represents a single dependency entry from the manifest.
 */
final class Dependency
{
    /**
     * @param string $key Unique identifier (e.g. 'php-cli', 'docker-engine')
     * @param string $section Section within host (e.g. 'system', 'docker', 'clients')
     * @param string $constraint Semver constraint (e.g. '>=8.4')
     * @param string $installer Installer type: apt, npm-global, github-release
     * @param string $package Package name
     * @param list<string> $sources Ordered list of sources to query
     * @param string|null $gpg GPG key fingerprint for apt repos that require GPG
     * @param string|null $onExistingBelowMin Per-dep override for on_existing_below_min
     * @param string|null $onUninstallPreExisting Per-dep override for on_uninstall_pre_existing
     */
    public function __construct(
        public readonly string $key,
        public readonly string $section,
        public readonly string $constraint,
        public readonly string $installer,
        public readonly string $package,
        public readonly array $sources,
        public readonly ?string $gpg = null,
        public readonly ?string $onExistingBelowMin = null,
        public readonly ?string $onUninstallPreExisting = null,
    ) {
    }
}
