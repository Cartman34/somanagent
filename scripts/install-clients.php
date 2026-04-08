#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Install or upgrade AI CLI clients locally and/or inside Docker containers
// Usage: php scripts/install-clients.php
// Usage: php scripts/install-clients.php [claude] [codex] [opencode]
// Usage: php scripts/install-clients.php --docker
// Usage: php scripts/install-clients.php codex opencode --docker --upgrade

require_once __DIR__ . '/src/bootstrap.php';

use SoManAgent\Script\Runner\InstallClientsRunner;

(new InstallClientsRunner())->handle($argv);
