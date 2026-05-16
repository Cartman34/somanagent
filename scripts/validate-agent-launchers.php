#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Cross-check AgentClientLauncher CLI flags against the local binary --help output
// Usage: php scripts/validate-agent-launchers.php

require_once __DIR__ . '/src/bootstrap.php';

use SoManAgent\Script\Runner\ValidateAgentLaunchersRunner;

(new ValidateAgentLaunchersRunner())->handle($argv);
