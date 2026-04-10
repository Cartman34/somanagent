#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Local backlog workflow helper for developer and reviewer commands
// Usage: php scripts/backlog.php task-book-next --agent agent-01
// Usage: php scripts/backlog.php feature-start --agent agent-01 --branch-type feat --body-file local/tmp/pr_body.md
// Usage: php scripts/backlog.php feature-review-approve my-feature --body-file local/tmp/pr_body.md

require_once __DIR__ . '/src/bootstrap.php';

use SoManAgent\Script\Runner\BacklogRunner;

(new BacklogRunner())->handle($argv);
