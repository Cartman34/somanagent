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

    private string $developer;

    /**
     * @param string $feature   Feature slug
     * @param string $developer Developer code
     */
    public function __construct(string $feature, string $developer)
    {
        $this->feature = $feature;
        $this->developer = $developer;
    }

    /**
     * @return string
     */
    public function getFeature(): string
    {
        return $this->feature;
    }

    /**
     * @return string
     */
    public function getDeveloper(): string
    {
        return $this->developer;
    }
}
