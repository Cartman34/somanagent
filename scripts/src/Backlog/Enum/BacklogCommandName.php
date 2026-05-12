<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Enum;

/**
 * Stable command names recognized by scripts/backlog.php for backlog management operations.
 */
enum BacklogCommandName: string
{
    case HELP = 'help';
    case BASE_UPDATE = 'base-update';
    case STATUS = 'status';
    case TASK_CREATE = 'task-create';
    case TODO_LIST = 'todo-list';
    case TASK_REMOVE = 'task-remove';
    case REVIEW_CANCEL = 'review-cancel';
    case REVIEW_CHECK = 'review-check';
    case REVIEW_APPROVE = 'review-approve';
    case REVIEW_REJECT = 'review-reject';
    case REVIEW_LIST = 'review-list';
    case REVIEW_NEXT = 'review-next';
    case REVIEW_NOTES = 'review-notes';
    case REVIEW_REQUEST = 'review-request';
    case TASK_REVIEW_CHECK = 'task-review-check';
    case TASK_REVIEW_REJECT = 'task-review-reject';
    case TASK_REVIEW_APPROVE = 'task-review-approve';
    case REWORK = 'rework';
    case ENTRY_MERGE = 'entry-merge';
    case ENTRY_RENAME = 'entry-rename';
    case WORK_START = 'work-start';
    case FEATURE_RELEASE = 'feature-release';
    case FEATURE_TASK_MERGE = 'feature-task-merge';
    case FEATURE_ASSIGN = 'feature-assign';
    case ENTRY_UNASSIGN = 'entry-unassign';
    case FEATURE_BLOCK = 'feature-block';
    case FEATURE_UNBLOCK = 'feature-unblock';
    case FEATURE_LIST = 'feature-list';
    case WORKTREE_LIST = 'worktree-list';
    case WORKTREE_CLEAN = 'worktree-clean';
    case WORKTREE_RESTORE = 'worktree-restore';
    case FEATURE_REVIEW_CHECK = 'feature-review-check';
    case FEATURE_REVIEW_REJECT = 'feature-review-reject';
    case FEATURE_REVIEW_APPROVE = 'feature-review-approve';
    case FEATURE_CLOSE = 'feature-close';
    case FEATURE_MERGE = 'feature-merge';

    /**
     * Returns true when this command mutates board, review, worktree, or associated state.
     *
     * Read-only commands skip the mutation lock entirely.
     */
    public function isMutating(): bool
    {
        return match($this) {
            self::STATUS,
            self::FEATURE_LIST,
            self::WORKTREE_LIST,
            self::TODO_LIST,
            self::REVIEW_LIST,
            self::REVIEW_NOTES,
            self::REVIEW_CHECK,
            self::FEATURE_REVIEW_CHECK,
            self::TASK_REVIEW_CHECK,
            self::HELP => false,
            default => true,
        };
    }
}
