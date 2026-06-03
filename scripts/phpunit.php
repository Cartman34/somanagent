#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Run PHPUnit on the project scopes
// Usage: php scripts/phpunit.php
// Usage: php scripts/phpunit.php --scope=backend
// Usage: php scripts/phpunit.php backend/tests/Unit/Service/MyServiceTest.php

require_once __DIR__ . '/src/bootstrap.php';

use Sowapps\SoManAgent\Script\Runner\PhpUnitRunner;

(new PhpUnitRunner())->handle($argv);
