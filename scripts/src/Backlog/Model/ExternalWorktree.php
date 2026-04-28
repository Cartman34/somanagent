<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Model;

use SoManAgent\Script\Backlog\Enum\WorktreeAction;

/**
 * Data object for a worktree not managed by a backlog agent.
 */
final class ExternalWorktree
{
    private string $path;

    private ?string $branch;

    private WorktreeAction $action;

    public function __construct(
        string $path,
        ?string $branch,
        WorktreeAction $action
    ) {
        $this->path = $path;
        $this->branch = $branch;
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

    public function getAction(): WorktreeAction
    {
        return $this->action;
    }
}
