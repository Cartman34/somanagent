<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\DevEnv;

use SoManAgent\Script\DevEnv\Model\Dependency;
use SoManAgent\Script\DevEnv\Model\Manifest;
use Symfony\Component\Yaml\Yaml;

/**
 * Parses a YAML manifest file into a Manifest model.
 *
 * Expected YAML structure:
 *   defaults:
 *     on_existing_below_min: upgrade|error|confirm
 *     on_uninstall_pre_existing: keep|restore
 *   host:
 *     <section>:
 *       <dep-key>:
 *         constraint: ">=X.Y"
 *         installer: apt|npm-global|github-release
 *         package: <name>
 *         sources: [<source>, ...]
 *         gpg: <fingerprint>              # optional, apt only
 *         on_existing_below_min: ...      # optional per-dep override
 *         on_uninstall_pre_existing: ...  # optional per-dep override
 */
final class ManifestParser
{
    /**
     * Parses the manifest file at the given path and returns a Manifest model.
     *
     * @throws \RuntimeException when the file is missing, unreadable, or structurally invalid
     */
    public function parseFile(string $path): Manifest
    {
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('Manifest file not found: %s', $path));
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException(sprintf('Cannot read manifest file: %s', $path));
        }

        return $this->parse($raw);
    }

    /**
     * Parses a YAML manifest string and returns a Manifest model.
     *
     * @throws \RuntimeException when the YAML is invalid or required fields are missing
     */
    public function parse(string $yaml): Manifest
    {
        $data = Yaml::parse($yaml);

        if (!is_array($data)) {
            throw new \RuntimeException('Manifest YAML must be a mapping.');
        }

        $rawDefaults = $data['defaults'] ?? [];
        $defaults = is_array($rawDefaults) ? $rawDefaults : [];
        $onExistingBelowMin = (string) ($defaults['on_existing_below_min'] ?? Manifest::DEFAULT_ON_EXISTING_BELOW_MIN);
        $onUninstallPreExisting = (string) ($defaults['on_uninstall_pre_existing'] ?? Manifest::DEFAULT_ON_UNINSTALL_PRE_EXISTING);

        $host = $data['host'] ?? [];
        if (!is_array($host)) {
            throw new \RuntimeException('Manifest host section must be a mapping.');
        }

        $dependencies = [];
        foreach ($host as $section => $sectionDeps) {
            if (!is_array($sectionDeps)) {
                continue;
            }
            foreach ($sectionDeps as $key => $depConfig) {
                if (!is_array($depConfig)) {
                    throw new \RuntimeException(sprintf(
                        'Invalid dependency config for %s.%s: expected a mapping.',
                        $section,
                        $key,
                    ));
                }
                $dependencies[] = $this->parseDependency((string) $key, (string) $section, $depConfig);
            }
        }

        return new Manifest($onExistingBelowMin, $onUninstallPreExisting, $dependencies);
    }

    /**
     * Parses a single dependency config array into a Dependency model.
     *
     * @param array<string, mixed> $config
     * @throws \RuntimeException when required fields are missing or sources is not a list
     */
    private function parseDependency(string $key, string $section, array $config): Dependency
    {
        foreach (['constraint', 'installer', 'package', 'sources'] as $required) {
            if (!isset($config[$required])) {
                throw new \RuntimeException(sprintf(
                    'Dependency %s.%s is missing required field: %s',
                    $section,
                    $key,
                    $required,
                ));
            }
        }

        $sources = $config['sources'];
        if (!is_array($sources)) {
            throw new \RuntimeException(sprintf(
                'Dependency %s.%s sources must be a list.',
                $section,
                $key,
            ));
        }

        return new Dependency(
            key: $key,
            section: $section,
            constraint: (string) $config['constraint'],
            installer: (string) $config['installer'],
            package: (string) $config['package'],
            sources: array_values(array_map('strval', $sources)),
            gpg: isset($config['gpg']) ? (string) $config['gpg'] : null,
            onExistingBelowMin: isset($config['on_existing_below_min'])
                ? (string) $config['on_existing_below_min']
                : null,
            onUninstallPreExisting: isset($config['on_uninstall_pre_existing'])
                ? (string) $config['on_uninstall_pre_existing']
                : null,
        );
    }
}
