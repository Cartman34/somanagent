#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Unified launcher for AI coding agents (Claude, Codex, OpenCode, Gemini) in dedicated worktrees
// Usage: php scripts/backlog-agent.php help
// Usage: php scripts/backlog-agent.php start claude --developer
// Usage: php scripts/backlog-agent.php start claude --developer --code=d04
// Usage: php scripts/backlog-agent.php list
// Usage: php scripts/backlog-agent.php status --code=d04
// Usage: php scripts/backlog-agent.php stop --code=d04
// Usage: php scripts/backlog-agent.php whoami
// Usage: php scripts/backlog-agent.php sessions --code=d04

require_once __DIR__ . '/src/bootstrap.php';

use Sowapps\SoManAgent\Script\Backlog\Agent\Runner\BacklogAgentRunner;
use Sowapps\SoManAgent\Script\WorktreeScriptProxy;

WorktreeScriptProxy::run($argv);

(new BacklogAgentRunner())->handle($argv);
