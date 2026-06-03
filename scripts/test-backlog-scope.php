#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Run unit tests for BacklogScopeService
// Usage: php scripts/test-backlog-scope.php

require_once __DIR__ . '/src/bootstrap.php';

use Sowapps\SoManAgent\Script\Backlog\Test\BacklogScopeServiceTest;

$test = new BacklogScopeServiceTest();
$failed = $test->run();
exit($failed > 0 ? 1 : 0);
