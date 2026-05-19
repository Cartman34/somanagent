<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Enum;

/**
 * Operator choice when a reviewer auto-picker finds a developer WA already occupied by an existing live reviewer session.
 */
enum WaOccupantChoice
{
    /**
     * Assign the entry to the existing reviewer session and attach to its tmux session.
     */
    case Accept;

    /**
     * Skip this entry and continue to the next review-stage entry.
     *
     * Only offered when at least one other candidate exists after the current entry.
     */
    case Pass;

    /**
     * Abort the picker entirely; no entry is claimed, no session is started.
     */
    case Quit;
}
