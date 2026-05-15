#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Manage Docker Compose services for the development environment
// Usage: php scripts/server.php <start|stop|restart|status|health>
// Usage: php scripts/server.php start [--minimal] [--preview-only|--dry-run] [--force]
// Usage: php scripts/server.php stop [--preview-only|--dry-run] [--force]
// Usage: php scripts/server.php restart [--minimal] [--preview-only|--dry-run] [--force]
// Usage: php scripts/server.php status
// Usage: php scripts/server.php health

require_once __DIR__ . '/src/bootstrap.php';

use SoManAgent\Script\Runner\ServerRunner;

(new ServerRunner())->handle($argv);
