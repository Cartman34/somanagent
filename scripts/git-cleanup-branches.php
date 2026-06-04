#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Delete stale git branches merged into main (and, for a human operator, on the remote)
// Usage: php scripts/git-cleanup-branches.php
// Usage: php scripts/git-cleanup-branches.php --dry-run
// Usage: php scripts/git-cleanup-branches.php --remote --remote-tests

require_once __DIR__ . '/src/bootstrap.php';

use Sowapps\SoManAgent\Script\Runner\GitCleanupBranchesRunner;

(new GitCleanupBranchesRunner())->handle($argv);
