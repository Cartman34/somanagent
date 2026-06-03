#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Run unit tests for BacklogConfig
// Usage: php scripts/test-backlog-config.php

require_once __DIR__ . '/src/bootstrap.php';

use Sowapps\SoManAgent\Script\Backlog\Test\BacklogConfigTest;

$test = new BacklogConfigTest();
$failed = $test->run();
exit($failed > 0 ? 1 : 0);
