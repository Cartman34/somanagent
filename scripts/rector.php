#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Apply automated code fixes to backend and scripts PHP sources via Rector
// Usage: php scripts/rector.php
// Usage: php scripts/rector.php --dry-run
// Usage: php scripts/rector.php --scope=backend --dry-run
// Usage: php scripts/rector.php --scope=scripts

require_once __DIR__ . '/src/bootstrap.php';

use SoManAgent\Script\Runner\RectorRunner;

(new RectorRunner())->handle($argv);
