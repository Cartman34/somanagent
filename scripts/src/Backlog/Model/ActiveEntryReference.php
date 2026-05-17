<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Model;

/**
 * Reference to an active entry used for worktree mapping.
 */
final class ActiveEntryReference
{
    private string $feature;

    private string $agent;

    public function __construct(string $feature, string $agent)
    {
        $this->feature = $feature;
        $this->agent = $agent;
    }

    public function getFeature(): string
    {
        return $this->feature;
    }

    public function getAgent(): string
    {
        return $this->agent;
    }
}
