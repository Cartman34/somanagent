#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Restore the exec bit on shebang-bearing scripts/*.php entrypoints
// Usage: php scripts/fix-permissions.php
// Usage: php scripts/fix-permissions.php --dry-run

require_once __DIR__ . '/src/bootstrap.php';

use Sowapps\SoManAgent\Script\Runner\FixPermissionsRunner;

(new FixPermissionsRunner())->handle($argv);
