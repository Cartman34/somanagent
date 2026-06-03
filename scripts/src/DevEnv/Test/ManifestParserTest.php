<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\DevEnv\Test;

use Sowapps\SoManAgent\Script\DevEnv\ManifestParser;
use Sowapps\SoManAgent\Script\DevEnv\Model\Manifest;

/**
 * Unit tests for ManifestParser.
 */
final class ManifestParserTest
{
    /**
     * Runs all test cases and returns the total number of failures.
     * @api
     */
    public function run(): int
    {
        $failed = 0;

        $failed += $this->testParseDefaults();
        $failed += $this->testParseDependencies();
        $failed += $this->testParseSources();
        $failed += $this->testParseOptionalGpg();
        $failed += $this->testParsePerDepOverrides();
        $failed += $this->testMissingConstraintThrows();
        $failed += $this->testDefaultsWhenAbsent();
        $failed += $this->testMultipleSections();

        return $failed;
    }

    private function testParseDefaults(): int
    {
        $yaml = <<<YAML
        defaults:
          on_existing_below_min: upgrade
          on_uninstall_pre_existing: keep
        host:
          system:
            git:
              constraint: ">=2.30"
              installer: apt
              package: git
              sources: [default]
        YAML;

        $manifest = (new ManifestParser())->parse($yaml);

        if ($manifest->onExistingBelowMin !== 'upgrade') {
            echo "FAIL testParseDefaults: expected on_existing_below_min=upgrade, got {$manifest->onExistingBelowMin}\n";
            return 1;
        }
        if ($manifest->onUninstallPreExisting !== 'keep') {
            echo "FAIL testParseDefaults: expected on_uninstall_pre_existing=keep, got {$manifest->onUninstallPreExisting}\n";
            return 1;
        }

        echo "OK testParseDefaults\n";
        return 0;
    }

    private function testParseDependencies(): int
    {
        $yaml = <<<YAML
        defaults:
          on_existing_below_min: upgrade
          on_uninstall_pre_existing: keep
        host:
          system:
            php-cli:
              constraint: ">=8.4"
              installer: apt
              package: php8.4-cli
              sources: [default]
          clients:
            claude:
              constraint: ">=1.0"
              installer: npm-global
              package: "@anthropic-ai/claude-code"
              sources: [npm]
        YAML;

        $manifest = (new ManifestParser())->parse($yaml);

        if (count($manifest->dependencies) !== 2) {
            echo 'FAIL testParseDependencies: expected 2 deps, got ' . count($manifest->dependencies) . "\n";
            return 1;
        }

        $phpCli = $manifest->dependencies[0];
        if ($phpCli->key !== 'php-cli') {
            echo "FAIL testParseDependencies: wrong key {$phpCli->key}\n";
            return 1;
        }
        if ($phpCli->section !== 'system') {
            echo "FAIL testParseDependencies: wrong section {$phpCli->section}\n";
            return 1;
        }
        if ($phpCli->constraint !== '>=8.4') {
            echo "FAIL testParseDependencies: wrong constraint {$phpCli->constraint}\n";
            return 1;
        }
        if ($phpCli->installer !== 'apt') {
            echo "FAIL testParseDependencies: wrong installer {$phpCli->installer}\n";
            return 1;
        }

        echo "OK testParseDependencies\n";
        return 0;
    }

    private function testParseSources(): int
    {
        $yaml = <<<YAML
        host:
          system:
            php-cli:
              constraint: ">=8.4"
              installer: apt
              package: php8.4-cli
              sources:
                - default
                - "ppa:ondrej/php"
        YAML;

        $manifest = (new ManifestParser())->parse($yaml);
        $dep = $manifest->dependencies[0];

        if ($dep->sources !== ['default', 'ppa:ondrej/php']) {
            echo 'FAIL testParseSources: wrong sources ' . implode(', ', $dep->sources) . "\n";
            return 1;
        }

        echo "OK testParseSources\n";
        return 0;
    }

    private function testParseOptionalGpg(): int
    {
        $yaml = <<<YAML
        host:
          docker:
            docker-engine:
              constraint: ">=24"
              installer: apt
              package: docker-ce
              sources:
                - "https://download.docker.com/linux/ubuntu"
              gpg: "0x9DC858229FC7DD38854AE2D88D81803C0EBFCD88"
        YAML;

        $manifest = (new ManifestParser())->parse($yaml);
        $dep = $manifest->dependencies[0];

        if ($dep->gpg !== '0x9DC858229FC7DD38854AE2D88D81803C0EBFCD88') {
            echo "FAIL testParseOptionalGpg: wrong gpg {$dep->gpg}\n";
            return 1;
        }

        echo "OK testParseOptionalGpg\n";
        return 0;
    }

    private function testParsePerDepOverrides(): int
    {
        $yaml = <<<YAML
        defaults:
          on_existing_below_min: upgrade
          on_uninstall_pre_existing: keep
        host:
          system:
            git:
              constraint: ">=2.30"
              installer: apt
              package: git
              sources: [default]
              on_existing_below_min: error
              on_uninstall_pre_existing: restore
        YAML;

        $manifest = (new ManifestParser())->parse($yaml);
        $dep = $manifest->dependencies[0];

        if ($dep->onExistingBelowMin !== 'error') {
            echo "FAIL testParsePerDepOverrides: wrong on_existing_below_min {$dep->onExistingBelowMin}\n";
            return 1;
        }
        if ($dep->onUninstallPreExisting !== 'restore') {
            echo "FAIL testParsePerDepOverrides: wrong on_uninstall_pre_existing {$dep->onUninstallPreExisting}\n";
            return 1;
        }

        echo "OK testParsePerDepOverrides\n";
        return 0;
    }

    private function testMissingConstraintThrows(): int
    {
        $yaml = <<<YAML
        host:
          system:
            git:
              installer: apt
              package: git
              sources: [default]
        YAML;

        try {
            (new ManifestParser())->parse($yaml);
            echo "FAIL testMissingConstraintThrows: expected RuntimeException\n";
            return 1;
        } catch (\RuntimeException) {
            echo "OK testMissingConstraintThrows\n";
            return 0;
        }
    }

    private function testDefaultsWhenAbsent(): int
    {
        $yaml = <<<YAML
        host:
          system:
            git:
              constraint: ">=2.30"
              installer: apt
              package: git
              sources: [default]
        YAML;

        $manifest = (new ManifestParser())->parse($yaml);

        if ($manifest->onExistingBelowMin !== Manifest::DEFAULT_ON_EXISTING_BELOW_MIN) {
            echo "FAIL testDefaultsWhenAbsent: wrong on_existing_below_min {$manifest->onExistingBelowMin}\n";
            return 1;
        }
        if ($manifest->onUninstallPreExisting !== Manifest::DEFAULT_ON_UNINSTALL_PRE_EXISTING) {
            echo "FAIL testDefaultsWhenAbsent: wrong on_uninstall_pre_existing {$manifest->onUninstallPreExisting}\n";
            return 1;
        }

        echo "OK testDefaultsWhenAbsent\n";
        return 0;
    }

    private function testMultipleSections(): int
    {
        $yaml = <<<YAML
        host:
          system:
            git:
              constraint: ">=2.30"
              installer: apt
              package: git
              sources: [default]
          docker:
            docker-ce:
              constraint: ">=24"
              installer: apt
              package: docker-ce
              sources: ["https://download.docker.com/linux/ubuntu"]
          clients:
            claude:
              constraint: ">=1.0"
              installer: npm-global
              package: "@anthropic-ai/claude-code"
              sources: [npm]
        YAML;

        $manifest = (new ManifestParser())->parse($yaml);

        if (count($manifest->dependencies) !== 3) {
            echo 'FAIL testMultipleSections: expected 3 deps, got ' . count($manifest->dependencies) . "\n";
            return 1;
        }

        $sections = array_map(fn($d) => $d->section, $manifest->dependencies);
        if ($sections !== ['system', 'docker', 'clients']) {
            echo 'FAIL testMultipleSections: wrong sections ' . implode(', ', $sections) . "\n";
            return 1;
        }

        echo "OK testMultipleSections\n";
        return 0;
    }
}
