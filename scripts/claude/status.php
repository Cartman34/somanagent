#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Display overall project status: Docker, migrations, schema, git
// Usage: php scripts/claude/status.php

require_once __DIR__ . '/../src/bootstrap.php';

use SoManAgent\Script\Runner\StatusRunner;

(new StatusRunner())->handle($argv);
