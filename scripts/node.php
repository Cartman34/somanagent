#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Run reusable commands inside the Node Docker container
// Usage: php scripts/node.php type-check
// Usage: php scripts/node.php run build
// Usage: php scripts/node.php exec npm install
// Usage: php scripts/node.php shell

require_once __DIR__ . '/src/bootstrap.php';

use SoManAgent\Script\Runner\NodeRunner;

(new NodeRunner())->handle($argv);
