<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Enum;

/**
 * Stable CLI option names accepted by scripts/backlog.php.
 *
 * Options flagged as global are accepted by every backlog command. Other options must be
 * declared in the per-command YAML help file under `scripts/resources/backlog/commands/`
 * to be accepted by the strict CLI validator.
 */
enum BacklogCliOption: string
{
    case AGENT = 'agent';
    case BOARD_FILE = 'board-file';
    case BODY_FILE = 'body-file';
    case BRANCH_TYPE = 'branch-type';
    case DRY_RUN = 'dry-run';
    case HELP = 'help';
    case NO_VERBOSE = 'no-verbose';
    case PR_BASE_BRANCH = 'pr-base-branch';
    case REVIEW_FILE = 'review-file';
    case TEST_MODE = 'test-mode';
    case VERBOSE = 'verbose';
    case WORKTREE_DIR = 'worktree-dir';

    /**
     * Returns the option names accepted by every backlog command.
     *
     * Global options are honored both by the help / no-command paths and by every dispatched
     * command. They are not duplicated in the per-command YAML help files.
     *
     * @return list<string>
     */
    public static function globalOptionNames(): array
    {
        return [
            self::DRY_RUN->value,
            self::VERBOSE->value,
            self::NO_VERBOSE->value,
            self::HELP->value,
            self::TEST_MODE->value,
            self::BOARD_FILE->value,
            self::REVIEW_FILE->value,
            self::WORKTREE_DIR->value,
            self::PR_BASE_BRANCH->value,
        ];
    }
}
