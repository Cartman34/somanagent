<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\DevEnv;

use SoManAgent\Script\DevEnv\Model\Dependency;

/**
 * Detects the installed versions of dependencies on the current system.
 *
 * Results are cached per dep key for the lifetime of this instance so that
 * multiple checks within a single command do not re-run the same queries.
 */
final class StateInspector
{
    /**
     * @var array<string, string|null> Key → installed version (null = not installed)
     */
    private array $cache = [];

    /**
     * @param CommandRunnerInterface $runner Used to run version-detection commands
     */
    public function __construct(
        private readonly CommandRunnerInterface $runner,
    ) {
    }

    /**
     * Returns the installed version for a dependency, or null when not installed.
     */
    public function getInstalledVersion(Dependency $dep): ?string
    {
        if (array_key_exists($dep->key, $this->cache)) {
            return $this->cache[$dep->key];
        }

        $version = match ($dep->installer) {
            'apt' => $this->detectApt($dep->package),
            'npm-global' => $this->detectNpm($dep->package),
            'github-release' => $this->detectBinary($dep->key),
            default => null,
        };

        $this->cache[$dep->key] = $version;

        return $version;
    }

    /**
     * Clears the internal version cache.
     *
     * Call this after installation to allow re-detection of updated versions.
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }

    /**
     * Detects the installed version of an apt package via dpkg-query.
     */
    private function detectApt(string $package): ?string
    {
        $output = $this->runner->output(sprintf(
            "dpkg-query -W -f='\${Version}' %s 2>/dev/null",
            escapeshellarg($package),
        ));

        if ($output === null) {
            return null;
        }

        $version = trim($output);

        return $version !== '' ? $version : null;
    }

    /**
     * Detects the installed version of a globally installed npm package.
     */
    private function detectNpm(string $package): ?string
    {
        $output = $this->runner->output(sprintf(
            'npm list -g %s --depth=0 --json 2>/dev/null',
            escapeshellarg($package),
        ));

        if ($output === null) {
            return null;
        }

        $data = json_decode($output, true);
        if (!is_array($data)) {
            return null;
        }

        $dependencies = $data['dependencies'] ?? [];
        if (!is_array($dependencies) || !isset($dependencies[$package])) {
            return null;
        }

        $entry = $dependencies[$package];
        if (!is_array($entry) || !isset($entry['version'])) {
            return null;
        }

        return (string) $entry['version'];
    }

    /**
     * Detects the version of a binary installed via GitHub release.
     *
     * Tries --version, version, and -v in order and extracts the first
     * semver-like pattern from the output.
     */
    private function detectBinary(string $key): ?string
    {
        foreach (['--version', 'version', '-v'] as $flag) {
            $output = $this->runner->output(sprintf(
                '%s %s 2>/dev/null',
                escapeshellarg($key),
                $flag,
            ));

            if ($output !== null && trim($output) !== '') {
                if (preg_match('/(\d+\.\d+(?:\.\d+)?)/', trim($output), $m)) {
                    return $m[1];
                }
            }
        }

        return null;
    }
}
