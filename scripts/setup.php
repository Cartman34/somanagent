#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Manage host-level dependencies and project setup for the development environment
// Usage: php scripts/setup.php install
// Usage: php scripts/setup.php install --preview-only
// Usage: php scripts/setup.php install --dry-run
// Usage: php scripts/setup.php install --force

require_once __DIR__ . '/src/bootstrap.php';

use Sowapps\SoManAgent\Script\Runner\SetupRunner;

(new SetupRunner())->handle($argv);
