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
    case ALL = 'all';
    case BASE = 'base';
    case BOARD_FILE = 'board-file';
    case BODY_FILE = 'body-file';
    case BRANCH_TYPE = 'branch-type';
    case CLEANUP = 'cleanup';
    case DEVELOPER = 'developer';
    case DRY_RUN = 'dry-run';
    case FEATURE = 'feature';
    case FORCE = 'force';
    case FORCE_NEW = 'force-new';
    case HELP = 'help';
    case INDEX = 'index';
    case MANAGER = 'manager';
    case MIGRATION_MARKER_FILE = 'migration-marker-file';
    case MIGRATIONS_DIR = 'migrations-dir';
    case NO_VERBOSE = 'no-verbose';
    case POSITION = 'position';
    case PR_BASE_BRANCH = 'pr-base-branch';
    case RESET = 'reset';
    case REVIEW_FILE = 'review-file';
    case REVIEWER = 'reviewer';
    case RUNNING = 'running';
    case TASK = 'task';
    case TEST_MODE = 'test-mode';
    case TYPE = 'type';
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
            self::MIGRATIONS_DIR->value,
            self::MIGRATION_MARKER_FILE->value,
            self::PR_BASE_BRANCH->value,
        ];
    }
}
