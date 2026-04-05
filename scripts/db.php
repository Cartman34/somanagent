#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Run database-related commands inside Docker containers (PostgreSQL + PHP)
// Usage: php scripts/db.php query "SELECT 1"
// Usage: php scripts/db.php exec -c "\\dt"
// Usage: php scripts/db.php shell
// Usage: php scripts/db.php reset [--fixtures [--force]]

require_once __DIR__ . '/src/bootstrap.php';

use SoManAgent\Script\Runner\DbRunner;

(new DbRunner())->handle($argv);
