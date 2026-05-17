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
    case BASE_UPDATE = 'base-update';
    case STATUS = 'status';
    case ENTRY_CREATE = 'entry-create';
    case TODO_LIST = 'todo-list';
    case TASK_REMOVE = 'task-remove';
    case REVIEW_CANCEL = 'review-cancel';
    case REVIEW_CHECK = 'review-check';
    case REVIEW_APPROVE = 'review-approve';
    case REVIEW_REJECT = 'review-reject';
    case REVIEW_AMEND = 'review-amend';
    case REVIEW_REOPEN = 'review-reopen';
    case REVIEW_LIST = 'review-list';
    case REVIEW_NEXT = 'review-next';
    case REVIEW_NOTES = 'review-notes';
    case REVIEW_REQUEST = 'review-request';
    case REWORK = 'rework';
    case ENTRY_MERGE = 'entry-merge';
    case ENTRY_RENAME = 'entry-rename';
    case ENTRY_SET_META = 'entry-set-meta';
    case WORK_START = 'work-start';
    case ENTRY_RELEASE = 'entry-release';
    case FEATURE_TASK_MERGE = 'feature-task-merge';
    case ENTRY_ASSIGN = 'entry-assign';
    case ENTRY_UNASSIGN = 'entry-unassign';
    case FEATURE_BLOCK = 'feature-block';
    case FEATURE_UNBLOCK = 'feature-unblock';
    case LIST = 'list';
    case WORKTREE_LIST = 'worktree-list';
    case WORKTREE_CLEAN = 'worktree-clean';
    case WORKTREE_RESTORE = 'worktree-restore';
    case FEATURE_CLOSE = 'feature-close';

    /**
     * Returns true when this command mutates board, review, worktree, or associated state.
     *
     * Read-only commands skip the mutation lock entirely.
     */
    public function isMutating(): bool
    {
        return match($this) {
            self::STATUS,
            self::LIST,
            self::WORKTREE_LIST,
            self::TODO_LIST,
            self::REVIEW_LIST,
            self::REVIEW_NOTES,
            self::REVIEW_CHECK => false,
            default => true,
        };
    }
}
