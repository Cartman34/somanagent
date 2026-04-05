#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Output a map of the React frontend: routes, pages, and API clients
// Usage: php scripts/claude/frontend-map.php
// Usage: php scripts/claude/frontend-map.php --json

require_once __DIR__ . '/../src/bootstrap.php';

use SoManAgent\Script\Runner\FrontendMapRunner;

(new FrontendMapRunner())->handle($argv);
