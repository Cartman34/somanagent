#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: List all available scripts with their description and usage examples
// Usage: php scripts/help.php
// Usage: php scripts/help.php <script-name>

require_once __DIR__ . '/src/bootstrap.php';

use SoManAgent\Script\Runner\HelpRunner;

(new HelpRunner())->handle($argv);
