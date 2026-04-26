<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog;

/**
 * Result of worktree classification.
 */
final class WorktreeClassification
{
    /** @var array<int, ManagedWorktree> */
    private array $managed;

    /** @var array<int, ExternalWorktree> */
    private array $external;

    /**
     * @param array<int, ManagedWorktree> $managed
     * @param array<int, ExternalWorktree> $external
     */
    public function __construct(array $managed, array $external)
    {
        $this->managed = $managed;
        $this->external = $external;
    }

    /**
     * @return array<int, ManagedWorktree>
     */
    public function getManaged(): array
    {
        return $this->managed;
    }

    /**
     * @return array<int, ExternalWorktree>
     */
    public function getExternal(): array
    {
        return $this->external;
    }
}
