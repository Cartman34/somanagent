#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Run PHPStan static analysis on backend and scripts PHP sources
// Usage: php scripts/phpstan.php
// Usage: php scripts/phpstan.php --scope=backend
// Usage: php scripts/phpstan.php --scope=scripts
// Usage: php scripts/phpstan.php backend/src/Controller/AgentController.php

require_once __DIR__ . '/src/bootstrap.php';

use Sowapps\SoManAgent\Script\Runner\PhpstanRunner;

(new PhpstanRunner())->handle($argv);
