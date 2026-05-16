#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Run reusable sequential validation campaigns for scripts/backlog.php
// Usage: php scripts/test-backlog-workflow.php
// Usage: php scripts/test-backlog-workflow.php --campaign help
// Usage: php scripts/test-backlog-workflow.php --campaign scoped-task-lifecycle
// Usage: php scripts/test-backlog-workflow.php --allow-remote --campaign feature-review-lifecycle

require_once __DIR__ . '/src/bootstrap.php';

use SoManAgent\Script\Runner\TestBacklogWorkflowRunner;

(new TestBacklogWorkflowRunner())->handle($argv);
