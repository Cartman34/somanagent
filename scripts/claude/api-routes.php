#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: List all REST routes from Symfony controllers by parsing #[Route] attributes
// Usage: php scripts/claude/api-routes.php
// Usage: php scripts/claude/api-routes.php --json

require_once __DIR__ . '/../src/bootstrap.php';

use SoManAgent\Script\Runner\ApiRoutesRunner;

(new ApiRoutesRunner())->handle($argv);
