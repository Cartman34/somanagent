#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Run unit tests for the generate-migration script helpers
// Usage: php scripts/tests/test-generate-migration.php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use Sowapps\SoManAgent\Script\Runner\Test\GenerateMigrationServiceTest;
use Sowapps\Toolkit\Console;

$console = Console::getInstance();

$test = new GenerateMigrationServiceTest();
$failed = $test->run();

if ($failed > 0) {
    $console->fail("{$failed} test(s) failed.");
}

$console->ok('All generate-migration tests passed.');
exit(0);
