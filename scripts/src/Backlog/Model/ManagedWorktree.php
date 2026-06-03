<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Model;

use Sowapps\SoManAgent\Script\Backlog\Enum\WorktreeState;
use Sowapps\SoManAgent\Script\Backlog\Enum\WorktreeAction;

/**
 * Data object for a worktree managed by a backlog agent.
 */
final class ManagedWorktree
{
    private string $path;

    private ?string $branch;

    private ?string $feature;

    private ?string $agent;

    private WorktreeState $state;

    private WorktreeAction $action;

    /**
     * Records all metadata for a backlog-managed worktree.
     */
    public function __construct(
        string $path,
        ?string $branch,
        ?string $feature,
        ?string $agent,
        WorktreeState $state,
        WorktreeAction $action
    ) {
        $this->path = $path;
        $this->branch = $branch;
        $this->feature = $feature;
        $this->agent = $agent;
        $this->state = $state;
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
     * Returns the feature slug associated with this worktree, or null if unassigned.
     */
    public function getFeature(): ?string
    {
        return $this->feature;
    }

    /**
     * Returns the agent code occupying this worktree, or null if unoccupied.
     */
    public function getAgent(): ?string
    {
        return $this->agent;
    }

    /**
     * Returns the current lifecycle state of the worktree.
     */
    public function getState(): WorktreeState
    {
        return $this->state;
    }

    /**
     * Returns the recommended action for this worktree.
     */
    public function getAction(): WorktreeAction
    {
        return $this->action;
    }
}
