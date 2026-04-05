#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Check application and connector health via the API
// Usage: php scripts/health.php
// Usage: php scripts/health.php --url http://localhost:8080

require_once __DIR__ . '/src/bootstrap.php';

use SoManAgent\Script\Runner\HealthRunner;

(new HealthRunner())->handle($argv);
