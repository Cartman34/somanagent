#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Run targeted backend/frontend validations for an explicit file list
// Usage: php scripts/validate-files.php <file> [file...]
// Usage: php scripts/validate-files.php --with-types <file> [file...]

require_once __DIR__ . '/src/bootstrap.php';

use SoManAgent\Script\Runner\ValidateFilesRunner;

(new ValidateFilesRunner())->handle($argv);
