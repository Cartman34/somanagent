#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Manage OpenCode CLI provider credentials with WSL as the source of truth and sync them to Docker
// Usage: php scripts/opencode-auth.php status
// Usage: php scripts/opencode-auth.php sync [--force]
// Usage: php scripts/opencode-auth.php login [provider] [--force]

require_once __DIR__ . '/src/bootstrap.php';

use SoManAgent\Script\Runner\OpenCodeAuthRunner;

(new OpenCodeAuthRunner())->handle($argv);
