#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Run unit tests for the DevEnv manifest/lockfile/resolver/inspector classes
// Usage: php scripts/tests/test-dev-env.php
// Usage: php scripts/tests/test-dev-env.php --suite=ManifestParserTest

require_once __DIR__ . '/../src/bootstrap.php';

use Sowapps\SoManAgent\Script\Console;
use Sowapps\SoManAgent\Script\DevEnv\Test\InstallPlannerTest;
use Sowapps\SoManAgent\Script\DevEnv\Test\LockfileManagerTest;
use Sowapps\SoManAgent\Script\DevEnv\Test\ManifestParserTest;
use Sowapps\SoManAgent\Script\DevEnv\Test\ManifestResolverTest;
use Sowapps\SoManAgent\Script\DevEnv\Test\ProjectDepsInstallerTest;
use Sowapps\SoManAgent\Script\DevEnv\Test\StateInspectorTest;
use Sowapps\SoManAgent\Script\DevEnv\Test\SystemSourceQuerierTest;
use Sowapps\SoManAgent\Script\DevEnv\Test\VersionConstraintTest;

$console = Console::getInstance();

$suite = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--suite=')) {
        $suite = substr($arg, 8);
    }
}

/** @var array<string, class-string> */
$suites = [
    'VersionConstraintTest'       => VersionConstraintTest::class,
    'SystemSourceQuerierTest'     => SystemSourceQuerierTest::class,
    'ManifestParserTest'          => ManifestParserTest::class,
    'ManifestResolverTest'        => ManifestResolverTest::class,
    'LockfileManagerTest'         => LockfileManagerTest::class,
    'StateInspectorTest'          => StateInspectorTest::class,
    'InstallPlannerTest'          => InstallPlannerTest::class,
    'ProjectDepsInstallerTest'    => ProjectDepsInstallerTest::class,
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
