#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: System dependency manager for Ubuntu 24+ — check and install required packages via apt
// Usage: php scripts/install-deps.php check
// Usage: php scripts/install-deps.php install

require_once __DIR__ . '/src/bootstrap.php';

use SoManAgent\Script\Runner\InstallDepsRunner;

(new InstallDepsRunner())->handle($argv);
