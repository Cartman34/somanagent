<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog;

/**
 * Stable Pull Request tags used in PR titles.
 */
enum PullRequestTag: string
{
    case WIP = 'WIP';
    case FEAT = 'FEAT';
    case FIX = 'FIX';
    case TECH = 'TECH';
    case DOC = 'DOC';
    case BLOCKED = 'BLOCKED';
}
