#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Manage Codex CLI auth with WSL as the source of truth and sync it to Docker
// Usage: php scripts/codex-auth.php status
// Usage: php scripts/codex-auth.php sync [--force]
// Usage: php scripts/codex-auth.php login [--force]

require_once __DIR__ . '/src/bootstrap.php';

use SoManAgent\Script\Runner\CodexAuthRunner;

(new CodexAuthRunner())->handle($argv);
