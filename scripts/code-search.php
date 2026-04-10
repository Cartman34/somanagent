#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Search a term across backend PHP and frontend TS/TSX source files
// Usage: php scripts/code-search.php <term>
// Usage: php scripts/code-search.php <term> --engine rg
// Usage: php scripts/code-search.php <term> --engine php
// Usage: php scripts/code-search.php <term> --backend
// Usage: php scripts/code-search.php <term> --frontend
// Usage: php scripts/code-search.php <term> --context N

require_once __DIR__ . '/src/bootstrap.php';

use SoManAgent\Script\Runner\CodeSearchRunner;

(new CodeSearchRunner())->handle($argv);
