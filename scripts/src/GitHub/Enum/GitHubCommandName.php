<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\GitHub\Enum;

/**
 * Stable command names recognized by scripts/github.php for pull-request operations.
 */
enum GitHubCommandName: string
{
    case PR_CREATE = 'pr-create';
    case PR_MERGE  = 'pr-merge';
    case PR_CLOSE  = 'pr-close';
    case PR_EDIT   = 'pr-edit';
    case PR_LIST   = 'pr-list';
    case PR_VIEW   = 'pr-view';
}
