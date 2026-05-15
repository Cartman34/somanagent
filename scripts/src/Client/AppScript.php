<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Client;

/**
 * Known project script entry-points used by ProjectScriptClient to build shell commands.
 */
enum AppScript: string
{
    case BACKLOG = 'scripts/backlog.php';
    case GITHUB = 'scripts/github.php';
    case REVIEW = 'scripts/review.php';
}
