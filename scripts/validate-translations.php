#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Validate translation key usage across source files
// Usage: php scripts/validate-translations.php

require_once __DIR__ . '/src/bootstrap.php';

use SoManAgent\Script\Runner\ValidateTranslationsRunner;

(new ValidateTranslationsRunner())->handle($argv);
