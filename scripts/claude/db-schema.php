#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Output a compact schema of all Doctrine entities by parsing #[ORM\...] attributes
// Usage: php scripts/claude/db-schema.php
// Usage: php scripts/claude/db-schema.php --json

require_once __DIR__ . '/../src/bootstrap.php';

use SoManAgent\Script\Runner\DbSchemaRunner;

(new DbSchemaRunner())->handle($argv);
