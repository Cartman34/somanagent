<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog;

/**
 * Stable backlog command KS shared across routing and workflow hints.
 */
final class BacklogCommand
{
    public const HELP = 'help';
    public const TASK_CREATE = 'task-create';
    public const TASK_TODO_LIST = 'task-todo-list';
    public const TASK_REMOVE = 'task-remove';
    public const TASK_REVIEW_NEXT = 'task-review-next';
    public const TASK_REVIEW_REQUEST = 'task-review-request';
    public const TASK_REVIEW_CHECK = 'task-review-check';
    public const TASK_REVIEW_REJECT = 'task-review-reject';
    public const TASK_REVIEW_APPROVE = 'task-review-approve';
    public const TASK_REWORK = 'task-rework';
    public const FEATURE_START = 'feature-start';
    public const FEATURE_RELEASE = 'feature-release';
    public const FEATURE_TASK_ADD = 'feature-task-add';
    public const FEATURE_TASK_MERGE = 'feature-task-merge';
    public const FEATURE_ASSIGN = 'feature-assign';
    public const FEATURE_UNASSIGN = 'feature-unassign';
    public const FEATURE_REWORK = 'feature-rework';
    public const FEATURE_BLOCK = 'feature-block';
    public const FEATURE_UNBLOCK = 'feature-unblock';
    public const FEATURE_LIST = 'feature-list';
    public const WORKTREE_LIST = 'worktree-list';
    public const WORKTREE_CLEAN = 'worktree-clean';
    public const FEATURE_STATUS = 'feature-status';
    public const FEATURE_REVIEW_NEXT = 'feature-review-next';
    public const FEATURE_REVIEW_REQUEST = 'feature-review-request';
    public const FEATURE_REVIEW_CHECK = 'feature-review-check';
    public const FEATURE_REVIEW_REJECT = 'feature-review-reject';
    public const FEATURE_REVIEW_APPROVE = 'feature-review-approve';
    public const FEATURE_CLOSE = 'feature-close';
    public const FEATURE_MERGE = 'feature-merge';

    private function __construct()
    {
    }
}
