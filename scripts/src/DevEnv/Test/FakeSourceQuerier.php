<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\DevEnv\Test;

use SoManAgent\Script\DevEnv\SourceQuerierInterface;

/**
 * Fake source querier for unit tests.
 *
 * Returns predefined version lists keyed by installer:source:package.
 */
final class FakeSourceQuerier implements SourceQuerierInterface
{
    /**
     * @var array<string, list<string>>
     */
    private array $responses = [];

    /**
     * Registers a version list for a specific installer/source/package combination.
     *
     * @param list<string> $versions
     */
    public function setVersions(string $installer, string $source, string $package, array $versions): void
    {
        $this->responses[$this->key($installer, $source, $package)] = $versions;
    }

    /**
     * {@inheritdoc}
     */
    public function queryVersions(string $installer, string $source, string $package): array
    {
        return $this->responses[$this->key($installer, $source, $package)] ?? [];
    }

    private function key(string $installer, string $source, string $package): string
    {
        return "{$installer}:{$source}:{$package}";
    }
}
