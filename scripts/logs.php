#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Stream logs from a Docker container in real time
// Usage: php scripts/logs.php [php|worker|node|db|nginx]
// Usage: php scripts/logs.php php
// Usage: php scripts/logs.php db --tail 50

require_once __DIR__ . '/src/bootstrap.php';

use SoManAgent\Script\Runner\LogsRunner;

(new LogsRunner())->handle($argv);
