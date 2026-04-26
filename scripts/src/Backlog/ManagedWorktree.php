<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog;

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

    public function getPath(): string
    {
        return $this->path;
    }

    public function getBranch(): ?string
    {
        return $this->branch;
    }

    public function getFeature(): ?string
    {
        return $this->feature;
    }

    public function getAgent(): ?string
    {
        return $this->agent;
    }

    public function getState(): WorktreeState
    {
        return $this->state;
    }

    public function getAction(): WorktreeAction
    {
        return $this->action;
    }
}
