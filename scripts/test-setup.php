#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Run subprocess integration tests for scripts/setup.php
// Usage: php scripts/test-setup.php

require_once __DIR__ . '/src/bootstrap.php';

use SoManAgent\Script\Setup\Test\SetupRunnerTest;

$test = new SetupRunnerTest();
$failed = $test->run();
exit($failed > 0 ? 1 : 0);
