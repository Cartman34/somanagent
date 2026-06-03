#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Run unit tests for scripts/src/Validation classes
// Usage: php scripts/test-validation.php
// Usage: php scripts/test-validation.php --suite=ScriptExecBitValidatorTest

declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

use Sowapps\SoManAgent\Script\Console;
use Sowapps\SoManAgent\Script\Validation\Test\ExecBitFixerTest;
use Sowapps\SoManAgent\Script\Validation\Test\ScriptExecBitValidatorTest;

$console = Console::getInstance();

$suite = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--suite=')) {
        $suite = substr($arg, 8);
    }
}

/** @var array<string, class-string> $suites */
$suites = [
    'ScriptExecBitValidatorTest' => ScriptExecBitValidatorTest::class,
    'ExecBitFixerTest' => ExecBitFixerTest::class,
];

if ($suite !== null && !isset($suites[$suite])) {
    $console->fail(sprintf("Unknown test suite '%s'. Available: %s", $suite, implode(', ', array_keys($suites))));
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

$console->ok('All validation tests passed.');
exit(0);
