#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Local backlog workflow helper for developer and reviewer commands
// Usage: php scripts/backlog.php
// Usage: php scripts/backlog.php --help
// Usage: php scripts/backlog.php list --stage=todo
// Usage: php scripts/backlog.php start --agent agent-01
// Usage: php scripts/backlog.php review-request --agent agent-01
// Usage: php scripts/backlog.php review-check --agent r01 my-feature
// Usage: php scripts/backlog.php review-check --agent r01 my-feature/my-task
// Usage: php scripts/backlog.php review-approve --agent r01 my-feature --body-file local/tmp/pr_body.md
// Usage: php scripts/backlog.php review-approve --agent r01 my-feature/my-task
// Usage: php scripts/backlog.php review-reject --agent r01 my-feature --body-file local/tmp/review.md
// Usage: php scripts/backlog.php review-reject --agent r01 my-feature/my-task --body-file local/tmp/review.md

require_once __DIR__ . '/src/bootstrap.php';

use Sowapps\Backlog\Runner\BacklogBoardRunner;

(new BacklogBoardRunner())->handle($argv);
