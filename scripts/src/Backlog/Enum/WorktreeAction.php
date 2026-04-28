<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Enum;

/**
 * Stable worktree actions suggested during worktree classification.
 */
enum WorktreeAction: string
{
    case CLEAN = 'clean';
    case KEEP = 'keep';
    case MANUAL_PRUNE = 'manual-prune';
    case MANUAL_REMOVE = 'manual-remove';
    case MANUAL_REVIEW = 'manual-review';
}
