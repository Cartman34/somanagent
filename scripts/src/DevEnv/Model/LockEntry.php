<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\DevEnv\Model;

/**
 * Represents a single resolved dependency entry in the lockfile.
 */
final class LockEntry
{
    /**
     * @param string $key Dependency identifier matching the manifest key
     * @param string $section Manifest section (system, docker, clients)
     * @param string $version Exact resolved version (never 'latest' or a range)
     * @param string $installer Installer type: apt, npm-global, github-release
     * @param string $package Package name
     * @param string $source Effective source used for resolution
     * @param bool $preExisting True when the package was already installed before setup; immutable once set
     * @param string|null $previousVersion Version installed before setup first touched it
     * @param SideEffects|null $sideEffects Resources added during installation (apt repo, GPG key)
     * @param \DateTimeImmutable|null $resolvedAt When this entry was last resolved
     * @param array<string, string> $overrides Per-dep lockfile overrides (e.g. on_uninstall_pre_existing)
     */
    public function __construct(
        public readonly string $key,
        public readonly string $section,
        public readonly string $version,
        public readonly string $installer,
        public readonly string $package,
        public readonly string $source,
        public readonly bool $preExisting,
        public readonly ?string $previousVersion,
        public readonly ?SideEffects $sideEffects,
        public readonly ?\DateTimeImmutable $resolvedAt,
        public readonly array $overrides = [],
    ) {
    }

    /**
     * Returns a copy with a new resolution applied, preserving pre_existing.
     *
     * Used when updating an existing entry: pre_existing is immutable,
     * but version, source, previous_version, side_effects, and resolved_at can change.
     */
    public function withResolution(
        string $version,
        string $source,
        ?string $previousVersion,
        ?SideEffects $sideEffects,
        \DateTimeImmutable $resolvedAt,
    ): self {
        return new self(
            $this->key,
            $this->section,
            $version,
            $this->installer,
            $this->package,
            $source,
            $this->preExisting,
            $previousVersion,
            $sideEffects,
            $resolvedAt,
            $this->overrides,
        );
    }

    /**
     * Returns a copy with the given per-dep override applied.
     */
    public function withOverride(string $property, string $value): self
    {
        $overrides = $this->overrides;
        $overrides[$property] = $value;

        return new self(
            $this->key,
            $this->section,
            $this->version,
            $this->installer,
            $this->package,
            $this->source,
            $this->preExisting,
            $this->previousVersion,
            $this->sideEffects,
            $this->resolvedAt,
            $overrides,
        );
    }

    /**
     * Returns a copy recording the result of an install or upgrade operation.
     *
     * Updates resolved_at, previous_version, and side_effects. The pre_existing flag
     * is immutable once true: if the lockfile already records pre_existing=true it is
     * preserved; otherwise it is set from the wasPreExisting argument determined at
     * install time (dep was already present on the system before this run).
     */
    public function withApplied(
        bool $wasPreExisting,
        ?string $previousVersion,
        ?SideEffects $sideEffects,
        \DateTimeImmutable $appliedAt,
    ): self {
        return new self(
            $this->key,
            $this->section,
            $this->version,
            $this->installer,
            $this->package,
            $this->source,
            $this->preExisting || $wasPreExisting,
            $previousVersion,
            $sideEffects ?? $this->sideEffects,
            $appliedAt,
            $this->overrides,
        );
    }

    /**
     * Returns a copy with the given per-dep override removed.
     */
    public function withoutOverride(string $property): self
    {
        $overrides = $this->overrides;
        unset($overrides[$property]);

        return new self(
            $this->key,
            $this->section,
            $this->version,
            $this->installer,
            $this->package,
            $this->source,
            $this->preExisting,
            $this->previousVersion,
            $this->sideEffects,
            $this->resolvedAt,
            $overrides,
        );
    }
}
