#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Apply automated code fixes to backend PHP sources via Rector
// Usage: php scripts/rector.php
// Usage: php scripts/rector.php --dry-run

require_once __DIR__ . '/src/bootstrap.php';

use SoManAgent\Script\Runner\RectorRunner;

(new RectorRunner())->handle($argv);
