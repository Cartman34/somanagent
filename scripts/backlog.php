#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Local backlog workflow helper for developer and reviewer commands
// Usage: php scripts/backlog.php
// Usage: php scripts/backlog.php help
// Usage: php scripts/backlog.php help work-start
// Usage: php scripts/backlog.php task-todo-list
// Usage: php scripts/backlog.php work-start --agent agent-01
// Usage: php scripts/backlog.php review-request --agent agent-01
// Usage: php scripts/backlog.php feature-review-approve my-feature --body-file local/tmp/pr_body.md
// Usage: php scripts/backlog.php task-review-approve my-feature/my-task

require_once __DIR__ . '/src/bootstrap.php';

use SoManAgent\Script\Runner\BacklogRunner;
use SoManAgent\Script\WorktreeScriptProxy;

WorktreeScriptProxy::run($argv);

(new BacklogRunner())->handle($argv);
