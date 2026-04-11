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

/** backlog.php must only run from WP, never from a WA or arbitrary subdirectory. */
$projectRoot = realpath(__DIR__ . '/..');
$currentWorkingDirectory = getcwd();
if ($projectRoot === false || $currentWorkingDirectory === false) {
    fwrite(STDERR, "❌ Unable to resolve the current workspace.\n");
    exit(1);
}

$normalizedProjectRoot = str_replace('\\', '/', $projectRoot);
$normalizedCurrentWorkingDirectory = str_replace('\\', '/', $currentWorkingDirectory);
if ($normalizedCurrentWorkingDirectory !== $normalizedProjectRoot) {
    fwrite(STDERR, "❌ Run backlog.php from WP only.\n");
    fwrite(STDERR, "Current cwd: {$normalizedCurrentWorkingDirectory}\n");
    fwrite(STDERR, "Expected WP: {$normalizedProjectRoot}\n");
    fwrite(STDERR, "Retry from WP: php scripts/backlog.php ...\n");
    exit(1);
}

(new BacklogRunner())->handle($argv);
