<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Enum;

enum BacklogCommandName: string
{
    case HELP = 'help';
    case STATUS = 'status';
    case TASK_CREATE = 'task-create';
    case TASK_TODO_LIST = 'task-todo-list';
    case TASK_REMOVE = 'task-remove';
    case REVIEW_NEXT = 'review-next';
    case TASK_REVIEW_REQUEST = 'task-review-request';
    case TASK_REVIEW_CHECK = 'task-review-check';
    case TASK_REVIEW_REJECT = 'task-review-reject';
    case TASK_REVIEW_APPROVE = 'task-review-approve';
    case TASK_REWORK = 'task-rework';
    case FEATURE_START = 'feature-start';
    case FEATURE_RELEASE = 'feature-release';
    case FEATURE_TASK_ADD = 'feature-task-add';
    case FEATURE_TASK_MERGE = 'feature-task-merge';
    case FEATURE_ASSIGN = 'feature-assign';
    case FEATURE_UNASSIGN = 'feature-unassign';
    case FEATURE_REWORK = 'feature-rework';
    case FEATURE_BLOCK = 'feature-block';
    case FEATURE_UNBLOCK = 'feature-unblock';
    case FEATURE_LIST = 'feature-list';
    case WORKTREE_LIST = 'worktree-list';
    case WORKTREE_CLEAN = 'worktree-clean';
    case WORKTREE_RESTORE = 'worktree-restore';
    case FEATURE_REVIEW_REQUEST = 'feature-review-request';
    case FEATURE_REVIEW_CHECK = 'feature-review-check';
    case FEATURE_REVIEW_REJECT = 'feature-review-reject';
    case FEATURE_REVIEW_APPROVE = 'feature-review-approve';
    case FEATURE_CLOSE = 'feature-close';
    case FEATURE_MERGE = 'feature-merge';
}
