<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\DevEnv;

use SoManAgent\Script\DevEnv\Model\LockEntry;
use SoManAgent\Script\DevEnv\Model\Lockfile;
use SoManAgent\Script\DevEnv\Model\SideEffects;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads and writes the dependency lockfile.
 *
 * Enforces the pre_existing immutability rule: once set in the file,
 * pre_existing is never modified by subsequent read/write cycles.
 * Per-dep overrides stored in the lockfile (v1: on_uninstall_pre_existing)
 * are preserved across re-resolution.
 */
final class LockfileManager
{
    private const DATE_FORMAT = \DateTimeInterface::ATOM;

    /**
     * Allowed per-dep override property names (v1).
     */
    private const ALLOWED_OVERRIDES = ['on_uninstall_pre_existing'];

    /**
     * Reads the lockfile at the given path and returns a Lockfile model.
     *
     * Returns an empty Lockfile when the file does not exist.
     *
     * @throws \RuntimeException when the file exists but cannot be read or parsed
     */
    public function read(string $path): Lockfile
    {
        if (!is_file($path)) {
            return new Lockfile(null, null, []);
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException(sprintf('Cannot read lockfile: %s', $path));
        }

        return $this->parse($raw);
    }

    /**
     * Writes the lockfile model to the given path as YAML.
     *
     * @throws \RuntimeException when the file cannot be written
     */
    public function write(string $path, Lockfile $lockfile): void
    {
        $data = $this->serialize($lockfile);
        $yaml = Yaml::dump($data, 4, 2, Yaml::DUMP_NULL_AS_TILDE);

        if (file_put_contents($path, $yaml) === false) {
            throw new \RuntimeException(sprintf('Cannot write lockfile: %s', $path));
        }
    }

    /**
     * Parses a YAML lockfile string into a Lockfile model.
     *
     * @throws \RuntimeException on YAML parse failure
     */
    public function parse(string $yaml): Lockfile
    {
        $data = Yaml::parse($yaml);

        if (!is_array($data)) {
            return new Lockfile(null, null, []);
        }

        $generatedAt = null;
        if (isset($data['generated_at']) && is_string($data['generated_at'])) {
            $parsed = \DateTimeImmutable::createFromFormat(self::DATE_FORMAT, $data['generated_at']);
            $generatedAt = $parsed !== false ? $parsed : null;
        }

        $manifestHash = isset($data['manifest_hash']) && is_string($data['manifest_hash'])
            ? $data['manifest_hash']
            : null;

        $host = $data['host'] ?? [];
        if (!is_array($host)) {
            return new Lockfile($generatedAt, $manifestHash, []);
        }

        $entries = [];
        foreach ($host as $section => $sectionEntries) {
            if (!is_array($sectionEntries)) {
                continue;
            }
            foreach ($sectionEntries as $key => $entryData) {
                if (!is_array($entryData)) {
                    continue;
                }
                $entry = $this->parseEntry((string) $key, (string) $section, $entryData);
                $entries[$entry->key] = $entry;
            }
        }

        return new Lockfile($generatedAt, $manifestHash, $entries);
    }

    /**
     * Parses a single lock entry from a YAML config array.
     *
     * @param array<string, mixed> $data
     */
    private function parseEntry(string $key, string $section, array $data): LockEntry
    {
        $resolvedAt = null;
        if (isset($data['resolved_at']) && is_string($data['resolved_at'])) {
            $parsed = \DateTimeImmutable::createFromFormat(self::DATE_FORMAT, $data['resolved_at']);
            $resolvedAt = $parsed !== false ? $parsed : null;
        }

        $sideEffects = null;
        if (isset($data['side_effects']) && is_array($data['side_effects'])) {
            $aptRepo = isset($data['side_effects']['apt_repo'])
                ? (string) $data['side_effects']['apt_repo']
                : null;
            $gpgKey = isset($data['side_effects']['gpg_key'])
                ? (string) $data['side_effects']['gpg_key']
                : null;
            $sideEffects = new SideEffects($aptRepo, $gpgKey);
        }

        $overrides = [];
        foreach (self::ALLOWED_OVERRIDES as $prop) {
            if (isset($data[$prop]) && is_string($data[$prop])) {
                $overrides[$prop] = $data[$prop];
            }
        }

        return new LockEntry(
            key: $key,
            section: $section,
            version: (string) ($data['version'] ?? ''),
            installer: (string) ($data['installer'] ?? ''),
            package: (string) ($data['package'] ?? ''),
            source: (string) ($data['source'] ?? ''),
            preExisting: (bool) ($data['pre_existing'] ?? false),
            previousVersion: isset($data['previous_version']) && is_string($data['previous_version'])
                ? $data['previous_version']
                : null,
            sideEffects: $sideEffects,
            resolvedAt: $resolvedAt,
            overrides: $overrides,
        );
    }

    /**
     * Serializes a Lockfile model to a YAML-ready nested array.
     *
     * @return array<string, mixed>
     */
    private function serialize(Lockfile $lockfile): array
    {
        $data = [
            'generated_at' => $lockfile->generatedAt?->format(self::DATE_FORMAT),
            'manifest_hash' => $lockfile->manifestHash,
            'host' => [],
        ];

        foreach ($lockfile->all() as $entry) {
            $data['host'][$entry->section][$entry->key] = $this->serializeEntry($entry);
        }

        return $data;
    }

    /**
     * Serializes a single LockEntry to a YAML-ready array.
     *
     * @return array<string, mixed>
     */
    private function serializeEntry(LockEntry $entry): array
    {
        $data = [
            'version' => $entry->version,
            'installer' => $entry->installer,
            'package' => $entry->package,
            'source' => $entry->source,
            'pre_existing' => $entry->preExisting,
            'previous_version' => $entry->previousVersion,
            'side_effects' => null,
            'resolved_at' => $entry->resolvedAt?->format(self::DATE_FORMAT),
        ];

        if ($entry->sideEffects !== null && !$entry->sideEffects->isEmpty()) {
            $sideEffectsData = [];
            if ($entry->sideEffects->aptRepo !== null) {
                $sideEffectsData['apt_repo'] = $entry->sideEffects->aptRepo;
            }
            if ($entry->sideEffects->gpgKey !== null) {
                $sideEffectsData['gpg_key'] = $entry->sideEffects->gpgKey;
            }
            $data['side_effects'] = $sideEffectsData;
        }

        foreach ($entry->overrides as $prop => $value) {
            $data[$prop] = $value;
        }

        return $data;
    }
}
