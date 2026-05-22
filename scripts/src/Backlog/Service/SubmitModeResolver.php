<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Service;

use SoManAgent\Script\Backlog\Enum\SubmitMode;

/**
 * Resolves the effective submit policy for a developer session.
 *
 * Priority (highest first):
 *   1. Per-session override from agent-sessions.json (`--submit-mode` CLI flag)
 *   2. Project-level config file (`workflow.submit`)  — TODO: implement after config system rebase
 *   3. Hardcoded fallback: SubmitMode::USER
 */
final class SubmitModeResolver
{
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

        // TODO: read workflow.submit from the project config file once the config system is available.

        return SubmitMode::USER;
    }
}
