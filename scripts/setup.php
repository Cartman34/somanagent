#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Full setup of SoManAgent (first run)
// Usage: php scripts/setup.php
// Usage: php scripts/setup.php --skip-frontend

$GLOBALS['somanagent_scripts_allow_autoinstall'] = true;
require_once __DIR__ . '/src/bootstrap.php';

use SoManAgent\Script\Runner\SetupRunner;

(new SetupRunner())->handle($argv);
