#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Run a Symfony bin/console command inside the PHP Docker container
// Usage: php scripts/console.php cache:clear
// Usage: php scripts/console.php doctrine:migrations:migrate --no-interaction

require_once __DIR__ . '/src/bootstrap.php';

use SoManAgent\Script\Runner\ConsoleRunner;

(new ConsoleRunner())->handle($argv);
