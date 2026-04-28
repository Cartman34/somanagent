<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Enum;

/**
 * Stable CLI option names accepted by scripts/backlog.php.
 */
enum BacklogCliOption: string
{
    case AGENT = 'agent';
    case BOARD_FILE = 'board-file';
    case BODY_FILE = 'body-file';
    case BRANCH_TYPE = 'branch-type';
    case FEATURE_TEXT = 'feature-text';
    case PR_BASE_BRANCH = 'pr-base-branch';
    case REVIEW_FILE = 'review-file';
    case TEST_MODE = 'test-mode';
}
