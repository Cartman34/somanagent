#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Run isolated local PHPUnit checks for backend unit tests from WSL without Docker services
// Usage: php scripts/validate-backend-tests.php <file> [file...]
// Usage: php scripts/validate-backend-tests.php --all

require_once __DIR__ . '/src/bootstrap.php';

use SoManAgent\Script\Runner\ValidateBackendTestsRunner;

(new ValidateBackendTestsRunner())->handle($argv);
