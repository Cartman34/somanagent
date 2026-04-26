<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog;

/**
 * Stable worktree states used during worktree classification.
 */
enum WorktreeState: string
{
    case ACTIVE = 'active';
    case BLOCKED = 'blocked';
    case DETACHED_MANAGED = 'detached-managed';
    case DIRTY = 'dirty';
    case ORPHAN = 'orphan';
    case PRUNABLE = 'prunable';
}
