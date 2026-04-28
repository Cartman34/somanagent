<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Client;

enum AppScript: string
{
    case GITHUB = 'scripts/github.php';
    case REVIEW = 'scripts/review.php';
}
