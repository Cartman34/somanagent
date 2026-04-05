#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: GitHub CLI helper — create PRs, merge, close, edit, list, view
// Usage: php scripts/github.php pr create --title "..." --head <branch> --body "..."
// Usage: php scripts/github.php pr merge <number> [--squash]
// Usage: php scripts/github.php pr list

require_once __DIR__ . '/src/bootstrap.php';

use SoManAgent\Script\Runner\GitHubRunner;

(new GitHubRunner())->handle($argv);
