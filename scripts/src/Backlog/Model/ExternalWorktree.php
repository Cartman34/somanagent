<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Model;

use Sowapps\SoManAgent\Script\Backlog\Enum\WorktreeAction;

/**
 * Data object for a worktree not managed by a backlog agent.
 */
final class ExternalWorktree
{
    private string $path;

    private ?string $branch;

    private WorktreeAction $action;

    /**
     * Records the path, branch, and recommended action for the external worktree.
     */
    public function __construct(
        string $path,
        ?string $branch,
        WorktreeAction $action
    ) {
        $this->path = $path;
        $this->branch = $branch;
        $this->action = $action;
    }

    /**
     * Returns the absolute path to the worktree directory.
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Returns the checked-out branch name, or null if the worktree is in detached-HEAD state.
     */
    public function getBranch(): ?string
    {
        return $this->branch;
    }

    /**
     * Returns the recommended action for this worktree.
     */
    public function getAction(): WorktreeAction
    {
        return $this->action;
    }
}
