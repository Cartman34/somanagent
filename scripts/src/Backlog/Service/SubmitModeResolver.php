<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Service;

use Sowapps\SoManAgent\Script\Backlog\Enum\SubmitMode;

/**
 * Resolves the effective submit policy for a developer session.
 *
 * Priority (highest first):
 *   1. Per-session override from agent-sessions.json (`--submit-mode` CLI flag)
 *   2. Project-level config file (`workflow.submit` in local/backlog/config.yaml)
 *   3. Hardcoded fallback: SubmitMode::USER
 */
final class SubmitModeResolver
{
    /**
     * @param BacklogConfig|null $config Project config reader; null disables config-file resolution.
     */
    public function __construct(private readonly ?BacklogConfig $config = null)
    {
    }

    /**
     * Returns the effective submit mode.
     *
     * @param SubmitMode|null $sessionOverride Per-session override stored in agent-sessions.json; null means no override.
     */
    public function resolve(?SubmitMode $sessionOverride): SubmitMode
    {
        if ($sessionOverride !== null) {
            return $sessionOverride;
        }

        if ($this->config !== null) {
            return $this->config->getWorkflowSubmit();
        }

        return SubmitMode::USER;
    }
}
