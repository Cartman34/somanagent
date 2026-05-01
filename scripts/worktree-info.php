#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Display git worktree context for the current script
// Usage: php scripts/worktree-info.php

require_once __DIR__ . '/src/bootstrap.php';

use SoManAgent\Script\Runner\WorktreeInfoRunner;

(new WorktreeInfoRunner())->handle($argv);
