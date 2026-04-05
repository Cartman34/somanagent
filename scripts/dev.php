#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Start or stop the development environment (Docker Compose)
// Usage: php scripts/dev.php
// Usage: php scripts/dev.php --stop

require_once __DIR__ . '/src/bootstrap.php';

use SoManAgent\Script\Runner\DevRunner;

(new DevRunner())->handle($argv);
