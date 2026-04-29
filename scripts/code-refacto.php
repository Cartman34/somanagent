#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Local code refactoring tools for backend source files.
// Usage: php scripts/code-refacto.php <command> [args] [--dry-run] [--verbose]
// Usage: php scripts/code-refacto.php help
// Usage: php scripts/code-refacto.php fix-inline-phpdoc
// Usage: php scripts/code-refacto.php add-missing-array-types --todo
// Usage: php scripts/code-refacto.php strip-what-comments

require_once __DIR__ . '/src/bootstrap.php';

use SoManAgent\Script\Runner\CodeRefactoRunner;

(new CodeRefactoRunner())->handle($argv);
