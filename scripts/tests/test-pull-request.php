#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Run unit tests for PullRequestService
// Usage: php scripts/tests/test-pull-request.php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use Sowapps\SoManAgent\Script\Console;
use Sowapps\SoManAgent\Script\Service\Test\PullRequestMergePrTest;

$console = Console::getInstance();

$console->line('▶ PullRequestMergePrTest...');
$test = new PullRequestMergePrTest();
$failed = $test->run();

if ($failed === 0) {
    $console->ok('All tests passed.');
} else {
    $console->fail("{$failed} test(s) failed.");
}

exit($failed > 0 ? 1 : 0);
