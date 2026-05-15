<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\DevEnv;

use SoManAgent\Script\DevEnv\Model\Dependency;
use SoManAgent\Script\DevEnv\Model\LockEntry;
use SoManAgent\Script\DevEnv\Model\Lockfile;
use SoManAgent\Script\DevEnv\Model\Manifest;

/**
 * Resolves manifest constraints to exact versions and produces a lockfile.
 *
 * For each dependency, sources are queried in order. The highest available
 * version satisfying the constraint is selected. When no source provides a
 * satisfying version, an error is collected and a RuntimeException is thrown
 * after all dependencies are processed (aggregated error).
 */
final class ManifestResolver
{
    /**
     * @param SourceQuerierInterface $querier Source querier (system or fake for tests)
     * @param VersionConstraint $constraint Version constraint checker
     */
    public function __construct(
        private readonly SourceQuerierInterface $querier,
        private readonly VersionConstraint $constraint = new VersionConstraint(),
    ) {
    }

    /**
     * Resolves all manifest dependencies and returns a complete lockfile.
     *
     * Merges with the existing lockfile to preserve pre_existing flags and per-dep
     * overrides. Pass an empty Lockfile when generating for the first time.
     *
     * @throws \RuntimeException when one or more dependencies cannot be resolved (aggregated)
     */
    public function resolve(Manifest $manifest, Lockfile $existing, \DateTimeImmutable $now): Lockfile
    {
        $manifestHash = hash('sha256', serialize($manifest));
        $errors = [];
        $lockfile = new Lockfile($now, $manifestHash, $existing->entries);

        foreach ($manifest->dependencies as $dep) {
            try {
                $entry = $this->resolveDependency($dep, $existing->get($dep->key), $now);
                $lockfile = $lockfile->withEntry($entry);
            } catch (\RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }

        if ($errors !== []) {
            throw new \RuntimeException(
                "Failed to resolve the following dependencies:\n" . implode("\n", $errors),
            );
        }

        return $lockfile->withGenerated($now, $manifestHash);
    }

    /**
     * Resolves a single dependency against its declared sources in order.
     *
     * The first source providing a version satisfying the constraint is used.
     * When no source provides a satisfying version, throws a RuntimeException
     * listing each source with the best available version it returned.
     *
     * @throws \RuntimeException on resolution failure
     */
    private function resolveDependency(Dependency $dep, ?LockEntry $existing, \DateTimeImmutable $now): LockEntry
    {
        $bestPerSource = [];

        foreach ($dep->sources as $source) {
            $versions = $this->querier->queryVersions($dep->installer, $source, $dep->package);
            $highest = $this->constraint->highest($versions, $dep->constraint);

            if ($highest !== null) {
                if ($existing !== null) {
                    return $existing->withResolution($highest, $source, $existing->previousVersion, null, $now);
                }

                return new LockEntry(
                    key: $dep->key,
                    section: $dep->section,
                    version: $highest,
                    installer: $dep->installer,
                    package: $dep->package,
                    source: $source,
                    preExisting: false,
                    previousVersion: null,
                    sideEffects: null,
                    resolvedAt: $now,
                );
            }

            $bestAvailable = $versions !== []
                ? ($this->constraint->highest($versions, '>=0') ?? 'none')
                : 'none';
            $bestPerSource[$source] = $bestAvailable;
        }

        $sourceList = implode(', ', array_map(
            static fn(string $src, string $best): string => sprintf('%s (best: %s)', $src, $best),
            array_keys($bestPerSource),
            array_values($bestPerSource),
        ));

        throw new \RuntimeException(sprintf(
            'No satisfying version for %s (%s) with constraint %s — sources tried: %s',
            $dep->key,
            $dep->package,
            $dep->constraint,
            $sourceList,
        ));
    }
}
