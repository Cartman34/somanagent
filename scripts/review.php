#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Run mechanical review checks on modified and untracked files
// Usage: php scripts/review.php

require_once __DIR__ . '/src/bootstrap.php';

use SoManAgent\Script\Runner\ReviewRunner;

(new ReviewRunner())->handle($argv);
