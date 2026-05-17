<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\DevEnv\Test;

use SoManAgent\Script\DevEnv\LockfileManager;
use SoManAgent\Script\DevEnv\Model\LockEntry;
use SoManAgent\Script\DevEnv\Model\Lockfile;
use SoManAgent\Script\DevEnv\Model\SideEffects;

/**
 * Unit tests for LockfileManager.
 */
final class LockfileManagerTest
{
    private string $tmpDir;

    /**
     * Creates a temporary directory for test fixtures.
     */
    public function __construct()
    {
        $this->tmpDir = sys_get_temp_dir() . '/dev-env-lockfile-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
    }

    /**
     * Removes the temporary directory on cleanup.
     */
    public function __destruct()
    {
        $this->rmdir($this->tmpDir);
    }

    /**
     * Runs all test cases and returns the total number of failures.
     * @api
     */
    public function run(): int
    {
        $failed = 0;

        $failed += $this->testReadReturnsEmptyWhenMissing();
        $failed += $this->testRoundTrip();
        $failed += $this->testPreExistingPreservedOnRoundTrip();
        $failed += $this->testSideEffectsRoundTrip();
        $failed += $this->testOverridesRoundTrip();
        $failed += $this->testParseEmptyLockfile();
        $failed += $this->testParseFullExample();

        return $failed;
    }

    private function testReadReturnsEmptyWhenMissing(): int
    {
        $manager = new LockfileManager();
        $lockfile = $manager->read($this->tmpDir . '/nonexistent.lock');

        if ($lockfile->entries !== []) {
            echo "FAIL testReadReturnsEmptyWhenMissing: expected empty entries\n";
            return 1;
        }

        echo "OK testReadReturnsEmptyWhenMissing\n";
        return 0;
    }

    private function testRoundTrip(): int
    {
        $now = new \DateTimeImmutable('2026-05-15T10:00:00+00:00');
        $entry = new LockEntry(
            key: 'git',
            section: 'system',
            version: '2.39.2',
            installer: 'apt',
            package: 'git',
            source: 'default',
            preExisting: false,
            previousVersion: null,
            sideEffects: null,
            resolvedAt: $now,
        );
        $lockfile = new Lockfile($now, 'sha256:abc123', ['git' => $entry]);

        $path = $this->tmpDir . '/test-roundtrip.lock';
        $manager = new LockfileManager();
        $manager->write($path, $lockfile);
        $loaded = $manager->read($path);

        $loaded_entry = $loaded->get('git');
        if ($loaded_entry === null) {
            echo "FAIL testRoundTrip: entry git missing after roundtrip\n";
            return 1;
        }
        if ($loaded_entry->version !== '2.39.2') {
            echo "FAIL testRoundTrip: wrong version {$loaded_entry->version}\n";
            return 1;
        }
        if ($loaded_entry->source !== 'default') {
            echo "FAIL testRoundTrip: wrong source {$loaded_entry->source}\n";
            return 1;
        }
        if ($loaded_entry->preExisting !== false) {
            echo "FAIL testRoundTrip: wrong pre_existing\n";
            return 1;
        }

        echo "OK testRoundTrip\n";
        return 0;
    }

    private function testPreExistingPreservedOnRoundTrip(): int
    {
        $now = new \DateTimeImmutable('2026-05-15T10:00:00+00:00');
        $entry = new LockEntry(
            key: 'claude',
            section: 'clients',
            version: '1.0.62',
            installer: 'npm-global',
            package: '@anthropic-ai/claude-code',
            source: 'npm',
            preExisting: true,
            previousVersion: '0.9.5',
            sideEffects: null,
            resolvedAt: $now,
        );
        $lockfile = new Lockfile($now, null, ['claude' => $entry]);

        $path = $this->tmpDir . '/test-preexisting.lock';
        $manager = new LockfileManager();
        $manager->write($path, $lockfile);
        $loaded = $manager->read($path);

        $loaded_entry = $loaded->get('claude');
        if ($loaded_entry === null) {
            echo "FAIL testPreExistingPreservedOnRoundTrip: entry missing\n";
            return 1;
        }
        if (!$loaded_entry->preExisting) {
            echo "FAIL testPreExistingPreservedOnRoundTrip: pre_existing must remain true\n";
            return 1;
        }
        if ($loaded_entry->previousVersion !== '0.9.5') {
            echo "FAIL testPreExistingPreservedOnRoundTrip: wrong previous_version {$loaded_entry->previousVersion}\n";
            return 1;
        }

        echo "OK testPreExistingPreservedOnRoundTrip\n";
        return 0;
    }

    private function testSideEffectsRoundTrip(): int
    {
        $now = new \DateTimeImmutable('2026-05-15T10:00:00+00:00');
        $entry = new LockEntry(
            key: 'docker-engine',
            section: 'docker',
            version: '24.0.7',
            installer: 'apt',
            package: 'docker-ce',
            source: 'https://download.docker.com/linux/ubuntu',
            preExisting: false,
            previousVersion: null,
            sideEffects: new SideEffects(
                aptRepo: '/etc/apt/sources.list.d/docker.list',
                gpgKey: '/etc/apt/keyrings/docker.gpg',
            ),
            resolvedAt: $now,
        );
        $lockfile = new Lockfile($now, null, ['docker-engine' => $entry]);

        $path = $this->tmpDir . '/test-sideeffects.lock';
        $manager = new LockfileManager();
        $manager->write($path, $lockfile);
        $loaded = $manager->read($path);

        $loaded_entry = $loaded->get('docker-engine');
        if ($loaded_entry === null) {
            echo "FAIL testSideEffectsRoundTrip: entry missing\n";
            return 1;
        }
        if ($loaded_entry->sideEffects === null) {
            echo "FAIL testSideEffectsRoundTrip: side_effects missing\n";
            return 1;
        }
        if ($loaded_entry->sideEffects->aptRepo !== '/etc/apt/sources.list.d/docker.list') {
            echo "FAIL testSideEffectsRoundTrip: wrong apt_repo\n";
            return 1;
        }
        if ($loaded_entry->sideEffects->gpgKey !== '/etc/apt/keyrings/docker.gpg') {
            echo "FAIL testSideEffectsRoundTrip: wrong gpg_key\n";
            return 1;
        }

        echo "OK testSideEffectsRoundTrip\n";
        return 0;
    }

    private function testOverridesRoundTrip(): int
    {
        $now = new \DateTimeImmutable('2026-05-15T10:00:00+00:00');
        $entry = new LockEntry(
            key: 'claude',
            section: 'clients',
            version: '1.0.62',
            installer: 'npm-global',
            package: '@anthropic-ai/claude-code',
            source: 'npm',
            preExisting: true,
            previousVersion: null,
            sideEffects: null,
            resolvedAt: $now,
            overrides: ['on_uninstall_pre_existing' => 'restore'],
        );
        $lockfile = new Lockfile($now, null, ['claude' => $entry]);

        $path = $this->tmpDir . '/test-overrides.lock';
        $manager = new LockfileManager();
        $manager->write($path, $lockfile);
        $loaded = $manager->read($path);

        $loaded_entry = $loaded->get('claude');
        if ($loaded_entry === null) {
            echo "FAIL testOverridesRoundTrip: entry missing\n";
            return 1;
        }
        if (($loaded_entry->overrides['on_uninstall_pre_existing'] ?? null) !== 'restore') {
            echo "FAIL testOverridesRoundTrip: override not preserved\n";
            return 1;
        }

        echo "OK testOverridesRoundTrip\n";
        return 0;
    }

    private function testParseEmptyLockfile(): int
    {
        $yaml = "generated_at: ~\nmanifest_hash: ~\nhost:\n  system: {}\n  docker: {}\n  clients: {}\n";
        $manager = new LockfileManager();
        $lockfile = $manager->parse($yaml);

        if ($lockfile->entries !== []) {
            echo 'FAIL testParseEmptyLockfile: expected empty entries, got ' . count($lockfile->entries) . "\n";
            return 1;
        }

        echo "OK testParseEmptyLockfile\n";
        return 0;
    }

    private function testParseFullExample(): int
    {
        $yaml = <<<YAML
        generated_at: "2026-05-15T10:30:00+02:00"
        manifest_hash: "sha256:abc123"
        host:
          system:
            php-cli:
              version: "8.4.3-1ubuntu1.0~22.04.1+9.2"
              installer: apt
              package: php8.4-cli
              source: "ppa:ondrej/php"
              pre_existing: false
              previous_version: ~
              side_effects:
                apt_repo: /etc/apt/sources.list.d/ondrej-php.list
                gpg_key: /etc/apt/keyrings/ondrej-php.gpg
              resolved_at: "2026-05-15T10:30:00+02:00"
          clients:
            claude:
              version: "1.0.62"
              installer: npm-global
              package: "@anthropic-ai/claude-code"
              source: npm
              pre_existing: true
              previous_version: "0.9.5"
              side_effects: ~
              resolved_at: "2026-05-15T10:30:00+02:00"
        YAML;

        $manager = new LockfileManager();
        $lockfile = $manager->parse($yaml);

        if (count($lockfile->entries) !== 2) {
            echo 'FAIL testParseFullExample: expected 2 entries, got ' . count($lockfile->entries) . "\n";
            return 1;
        }

        $phpCli = $lockfile->get('php-cli');
        if ($phpCli === null) {
            echo "FAIL testParseFullExample: php-cli entry missing\n";
            return 1;
        }
        if ($phpCli->version !== '8.4.3-1ubuntu1.0~22.04.1+9.2') {
            echo "FAIL testParseFullExample: wrong php-cli version\n";
            return 1;
        }
        if ($phpCli->sideEffects === null || $phpCli->sideEffects->aptRepo !== '/etc/apt/sources.list.d/ondrej-php.list') {
            echo "FAIL testParseFullExample: php-cli side_effects wrong\n";
            return 1;
        }

        $claude = $lockfile->get('claude');
        if ($claude === null || !$claude->preExisting || $claude->previousVersion !== '0.9.5') {
            echo "FAIL testParseFullExample: claude entry wrong\n";
            return 1;
        }

        echo "OK testParseFullExample\n";
        return 0;
    }

    /**
     * Recursively removes a directory and its contents.
     */
    private function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
