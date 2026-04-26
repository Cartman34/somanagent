<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog;

/**
 * Reference to an active entry used for worktree mapping.
 */
final class ActiveEntryReference
{
    private string $feature;

    private string $agent;

    private ?string $branch;

    public function __construct(string $feature, string $agent, ?string $branch = null)
    {
        $this->feature = $feature;
        $this->agent = $agent;
        $this->branch = $branch;
    }

    public function getFeature(): string
    {
        return $this->feature;
    }

    public function getAgent(): string
    {
        return $this->agent;
    }

    public function getBranch(): ?string
    {
        return $this->branch;
    }
}
