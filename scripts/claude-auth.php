#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Manage Claude CLI auth with WSL as the source of truth and sync it to Docker
// Usage: php scripts/claude-auth.php status
// Usage: php scripts/claude-auth.php sync [--force]
// Usage: php scripts/claude-auth.php login [--force]

require_once __DIR__ . '/src/bootstrap.php';

use SoManAgent\Script\Runner\ClaudeAuthRunner;

(new ClaudeAuthRunner())->handle($argv);
