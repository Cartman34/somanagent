#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Run Doctrine migrations inside the PHP container
// Usage: php scripts/migrate.php
// Usage: php scripts/migrate.php --dry-run

require_once __DIR__ . '/src/bootstrap.php';

use SoManAgent\Script\Runner\MigrateRunner;

(new MigrateRunner())->handle($argv);
