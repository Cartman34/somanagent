<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\DevEnv\Model;

/**
 * Represents the complete resolved lockfile.
 *
 * Entries are indexed by their key for fast lookup.
 */
final class Lockfile
{
    /**
     * @param \DateTimeImmutable|null $generatedAt When the lockfile was last generated
     * @param string|null $manifestHash SHA-256 hash of the manifest at generation time
     * @param array<string, LockEntry> $entries Resolved entries indexed by dep key
     */
    public function __construct(
        public readonly ?\DateTimeImmutable $generatedAt,
        public readonly ?string $manifestHash,
        public readonly array $entries,
    ) {
    }

    /**
     * Returns the lock entry for the given key, or null if not found.
     */
    public function get(string $key): ?LockEntry
    {
        return $this->entries[$key] ?? null;
    }

    /**
     * Returns a copy with the given entry added or replaced.
     */
    public function withEntry(LockEntry $entry): self
    {
        $entries = $this->entries;
        $entries[$entry->key] = $entry;

        return new self($this->generatedAt, $this->manifestHash, $entries);
    }

    /**
     * Returns a copy with the given entry removed.
     */
    public function withoutEntry(string $key): self
    {
        $entries = $this->entries;
        unset($entries[$key]);

        return new self($this->generatedAt, $this->manifestHash, $entries);
    }

    /**
     * Returns a copy with updated generation metadata.
     */
    public function withGenerated(\DateTimeImmutable $generatedAt, string $manifestHash): self
    {
        return new self($generatedAt, $manifestHash, $this->entries);
    }

    /**
     * Returns all lock entries as a flat list in insertion order.
     *
     * @return list<LockEntry>
     */
    public function all(): array
    {
        return array_values($this->entries);
    }
}
