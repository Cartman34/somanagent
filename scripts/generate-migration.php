#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Generate a Doctrine migration using an isolated temporary database
// Usage: php scripts/generate-migration.php

require_once __DIR__ . '/src/bootstrap.php';

use Sowapps\SoManAgent\Script\Runner\GenerateMigrationRunner;

(new GenerateMigrationRunner())->handle($argv);
