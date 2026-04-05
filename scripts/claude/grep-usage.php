#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Search a term across backend PHP and frontend TS/TSX source files
// Usage: php scripts/claude/grep-usage.php <term>
// Usage: php scripts/claude/grep-usage.php <term> --backend
// Usage: php scripts/claude/grep-usage.php <term> --frontend
// Usage: php scripts/claude/grep-usage.php <term> --context N

require_once __DIR__ . '/../src/bootstrap.php';

use SoManAgent\Script\Runner\GrepUsageRunner;

(new GrepUsageRunner())->handle($argv);
