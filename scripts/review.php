#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Run mechanical review checks on modified, untracked, and optional committed files
// Usage: php scripts/review.php [--base=<ref>]

require_once __DIR__ . '/src/bootstrap.php';

use SoManAgent\Script\Runner\ReviewRunner;

(new ReviewRunner())->handle($argv);
