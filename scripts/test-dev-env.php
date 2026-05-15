#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Run unit tests for the DevEnv manifest/lockfile/resolver/inspector classes
// Usage: php scripts/test-dev-env.php
// Usage: php scripts/test-dev-env.php --suite=ManifestParserTest

require_once __DIR__ . '/src/bootstrap.php';

use SoManAgent\Script\Console;
use SoManAgent\Script\DevEnv\Test\LockfileManagerTest;
use SoManAgent\Script\DevEnv\Test\ManifestParserTest;
use SoManAgent\Script\DevEnv\Test\ManifestResolverTest;
use SoManAgent\Script\DevEnv\Test\StateInspectorTest;
use SoManAgent\Script\DevEnv\Test\VersionConstraintTest;

$console = Console::getInstance();

$suite = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--suite=')) {
        $suite = substr($arg, 8);
    }
}

/** @var array<string, class-string> */
$suites = [
    'VersionConstraintTest' => VersionConstraintTest::class,
    'ManifestParserTest' => ManifestParserTest::class,
    'ManifestResolverTest' => ManifestResolverTest::class,
    'LockfileManagerTest' => LockfileManagerTest::class,
    'StateInspectorTest' => StateInspectorTest::class,
];

if ($suite !== null && !isset($suites[$suite])) {
    $console->fail(sprintf(
        "Unknown test suite '%s'. Available: %s",
        $suite,
        implode(', ', array_keys($suites)),
    ));
}

$toRun = $suite !== null ? [$suite => $suites[$suite]] : $suites;

$totalFailed = 0;

foreach ($toRun as $name => $class) {
    $console->line("▶ {$name}...");
    $test = new $class();
    $failed = $test->run();
    $totalFailed += $failed;
    if ($failed === 0) {
        $console->ok('All tests passed.');
    } else {
        $console->line("  ❌ {$failed} test(s) failed.");
    }
    $console->line('');
}

if ($totalFailed > 0) {
    $console->fail("{$totalFailed} test(s) failed.");
}

$console->ok('All dev-env tests passed.');
exit(0);
