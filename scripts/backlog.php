#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Local backlog workflow helper for developer and reviewer commands
// Usage: php scripts/backlog.php
// Usage: php scripts/backlog.php help
// Usage: php scripts/backlog.php help feature-start
// Usage: php scripts/backlog.php task-todo-list
// Usage: php scripts/backlog.php feature-start --agent agent-01
// Usage: php scripts/backlog.php feature-review-approve my-feature --body-file local/tmp/pr_body.md
// Usage: php scripts/backlog.php task-review-request --agent agent-01
// Usage: php scripts/backlog.php task-review-approve my-feature/my-task

require_once __DIR__ . '/src/bootstrap.php';

use SoManAgent\Script\Runner\BacklogRunner;
use SoManAgent\Script\WorktreeScriptProxy;

WorktreeScriptProxy::run($argv);

/** backlog.php must run from the project root (main or linked worktree with --force-current-worktree). */
$projectRoot = realpath(__DIR__ . '/..');
$currentWorkingDirectory = getcwd();
if ($projectRoot === false || $currentWorkingDirectory === false) {
    fwrite(STDERR, "❌ Unable to resolve the current workspace.\n");
    exit(1);
}

$normalizedProjectRoot = str_replace('\\', '/', $projectRoot);
$normalizedCurrentWorkingDirectory = str_replace('\\', '/', $currentWorkingDirectory);
if ($normalizedCurrentWorkingDirectory !== $normalizedProjectRoot) {
    fwrite(STDERR, "❌ Run backlog.php from the project root.\n");
    fwrite(STDERR, "Current directory: {$normalizedCurrentWorkingDirectory}\n");
    fwrite(STDERR, "Expected root:     {$normalizedProjectRoot}\n");
    fwrite(STDERR, "Retry from root:   php scripts/backlog.php ...\n");
    exit(1);
}

(new BacklogRunner())->handle($argv);
