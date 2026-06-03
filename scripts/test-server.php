#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Run tests for scripts/server.php
// Usage: php scripts/test-server.php

require_once __DIR__ . '/src/bootstrap.php';

use Sowapps\SoManAgent\Script\Console;
use Sowapps\SoManAgent\Script\Server\Test\ServerRunnerTest;

$console = Console::getInstance();

$console->line('▶ ServerRunnerTest...');
$test = new ServerRunnerTest();
$failed = $test->run();

if ($failed === 0) {
    $console->ok('All tests passed.');
} else {
    $console->fail("{$failed} test(s) failed.");
}

exit($failed > 0 ? 1 : 0);
