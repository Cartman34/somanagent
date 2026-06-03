<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\DevEnv\Test;

use Sowapps\SoManAgent\Script\DevEnv\ManifestParser;
use Sowapps\SoManAgent\Script\DevEnv\ManifestResolver;
use Sowapps\SoManAgent\Script\DevEnv\Model\Lockfile;
use Sowapps\SoManAgent\Script\DevEnv\Model\LockEntry;
use Sowapps\SoManAgent\Script\DevEnv\Test\FakeSourceQuerier;
/**
 * Unit tests for ManifestResolver.
 */
final class ManifestResolverTest
{
    private \DateTimeImmutable $now;

    /**
     * Initializes shared test fixtures.
     */
    public function __construct()
    {
        $this->now = new \DateTimeImmutable('2026-05-15T10:00:00+00:00');
    }

    /**
     * Runs all test cases and returns the total number of failures.
     * @api
     */
    public function run(): int
    {
        $failed = 0;

        $failed += $this->testResolvesHighestSatisfyingVersion();
        $failed += $this->testFallsBackToSecondSource();
        $failed += $this->testThrowsOnNoSatisfyingVersion();
        $failed += $this->testAggregatesMultipleErrors();
        $failed += $this->testPreservesPreExistingFlag();
        $failed += $this->testPreservesPerDepOverrides();

        return $failed;
    }

    private function testResolvesHighestSatisfyingVersion(): int
    {
        $querier = new FakeSourceQuerier();
        $querier->setVersions('apt', 'default', 'git', ['2.34.1', '2.39.2']);

        $manifest = (new ManifestParser())->parse(<<<YAML
        host:
          system:
            git:
              constraint: ">=2.30"
              installer: apt
              package: git
              sources: [default]
        YAML);

        $lockfile = (new ManifestResolver($querier))->resolve($manifest, new Lockfile(null, null, []), $this->now);

        $entry = $lockfile->get('git');
        if ($entry === null) {
            echo "FAIL testResolvesHighestSatisfyingVersion: entry git missing\n";
            return 1;
        }
        if ($entry->version !== '2.39.2') {
            echo "FAIL testResolvesHighestSatisfyingVersion: expected 2.39.2, got {$entry->version}\n";
            return 1;
        }
        if ($entry->source !== 'default') {
            echo "FAIL testResolvesHighestSatisfyingVersion: expected source=default, got {$entry->source}\n";
            return 1;
        }

        echo "OK testResolvesHighestSatisfyingVersion\n";
        return 0;
    }

    private function testFallsBackToSecondSource(): int
    {
        $querier = new FakeSourceQuerier();
        // default has old version that doesn't satisfy >=8.4
        $querier->setVersions('apt', 'default', 'php8.4-cli', ['7.4.0']);
        // ppa has satisfying version
        $querier->setVersions('apt', 'ppa:ondrej/php', 'php8.4-cli', ['8.4.3']);

        $manifest = (new ManifestParser())->parse(<<<YAML
        host:
          system:
            php-cli:
              constraint: ">=8.4"
              installer: apt
              package: php8.4-cli
              sources:
                - default
                - "ppa:ondrej/php"
        YAML);

        $lockfile = (new ManifestResolver($querier))->resolve($manifest, new Lockfile(null, null, []), $this->now);

        $entry = $lockfile->get('php-cli');
        if ($entry === null) {
            echo "FAIL testFallsBackToSecondSource: entry php-cli missing\n";
            return 1;
        }
        if ($entry->version !== '8.4.3') {
            echo "FAIL testFallsBackToSecondSource: expected 8.4.3, got {$entry->version}\n";
            return 1;
        }
        if ($entry->source !== 'ppa:ondrej/php') {
            echo "FAIL testFallsBackToSecondSource: expected ppa source, got {$entry->source}\n";
            return 1;
        }

        echo "OK testFallsBackToSecondSource\n";
        return 0;
    }

    private function testThrowsOnNoSatisfyingVersion(): int
    {
        $querier = new FakeSourceQuerier();
        $querier->setVersions('apt', 'default', 'tmux', ['2.8']);

        $manifest = (new ManifestParser())->parse(<<<YAML
        host:
          system:
            tmux:
              constraint: ">=3.2"
              installer: apt
              package: tmux
              sources: [default]
        YAML);

        try {
            (new ManifestResolver($querier))->resolve($manifest, new Lockfile(null, null, []), $this->now);
            echo "FAIL testThrowsOnNoSatisfyingVersion: expected RuntimeException\n";
            return 1;
        } catch (\RuntimeException $e) {
            if (!str_contains($e->getMessage(), 'tmux')) {
                echo "FAIL testThrowsOnNoSatisfyingVersion: error should mention tmux\n";
                return 1;
            }
        }

        echo "OK testThrowsOnNoSatisfyingVersion\n";
        return 0;
    }

    private function testAggregatesMultipleErrors(): int
    {
        $querier = new FakeSourceQuerier();
        // No versions for either dep

        $manifest = (new ManifestParser())->parse(<<<YAML
        host:
          system:
            git:
              constraint: ">=2.30"
              installer: apt
              package: git
              sources: [default]
            tmux:
              constraint: ">=3.2"
              installer: apt
              package: tmux
              sources: [default]
        YAML);

        try {
            (new ManifestResolver($querier))->resolve($manifest, new Lockfile(null, null, []), $this->now);
            echo "FAIL testAggregatesMultipleErrors: expected RuntimeException\n";
            return 1;
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            if (!str_contains($msg, 'git') || !str_contains($msg, 'tmux')) {
                echo "FAIL testAggregatesMultipleErrors: error should mention both git and tmux\n";
                return 1;
            }
        }

        echo "OK testAggregatesMultipleErrors\n";
        return 0;
    }

    private function testPreservesPreExistingFlag(): int
    {
        $querier = new FakeSourceQuerier();
        $querier->setVersions('apt', 'default', 'git', ['2.39.2']);

        $manifest = (new ManifestParser())->parse(<<<YAML
        host:
          system:
            git:
              constraint: ">=2.30"
              installer: apt
              package: git
              sources: [default]
        YAML);

        $existingEntry = new LockEntry(
            key: 'git',
            section: 'system',
            version: '2.34.1',
            installer: 'apt',
            package: 'git',
            source: 'default',
            preExisting: true,
            previousVersion: '2.30.0',
            sideEffects: null,
            resolvedAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );
        $existing = new Lockfile(null, null, ['git' => $existingEntry]);

        $lockfile = (new ManifestResolver($querier))->resolve($manifest, $existing, $this->now);

        $entry = $lockfile->get('git');
        if ($entry === null) {
            echo "FAIL testPreservesPreExistingFlag: entry missing\n";
            return 1;
        }
        if (!$entry->preExisting) {
            echo "FAIL testPreservesPreExistingFlag: pre_existing must be preserved as true\n";
            return 1;
        }
        if ($entry->version !== '2.39.2') {
            echo "FAIL testPreservesPreExistingFlag: version should update to 2.39.2\n";
            return 1;
        }

        echo "OK testPreservesPreExistingFlag\n";
        return 0;
    }

    private function testPreservesPerDepOverrides(): int
    {
        $querier = new FakeSourceQuerier();
        $querier->setVersions('npm-global', 'npm', '@anthropic-ai/claude-code', ['1.0.62']);

        $manifest = (new ManifestParser())->parse(<<<YAML
        host:
          clients:
            claude:
              constraint: ">=1.0"
              installer: npm-global
              package: "@anthropic-ai/claude-code"
              sources: [npm]
        YAML);

        $existingEntry = new LockEntry(
            key: 'claude',
            section: 'clients',
            version: '1.0.0',
            installer: 'npm-global',
            package: '@anthropic-ai/claude-code',
            source: 'npm',
            preExisting: false,
            previousVersion: null,
            sideEffects: null,
            resolvedAt: null,
            overrides: ['on_uninstall_pre_existing' => 'restore'],
        );
        $existing = new Lockfile(null, null, ['claude' => $existingEntry]);

        $lockfile = (new ManifestResolver($querier))->resolve($manifest, $existing, $this->now);

        $entry = $lockfile->get('claude');
        if ($entry === null) {
            echo "FAIL testPreservesPerDepOverrides: entry missing\n";
            return 1;
        }
        if (($entry->overrides['on_uninstall_pre_existing'] ?? null) !== 'restore') {
            echo "FAIL testPreservesPerDepOverrides: per-dep override not preserved\n";
            return 1;
        }

        echo "OK testPreservesPerDepOverrides\n";
        return 0;
    }
}
