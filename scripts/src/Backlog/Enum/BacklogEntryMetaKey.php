<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Enum;

/**
 * Stable metadata key names used in backlog board entries.
 */
enum BacklogEntryMetaKey: string
{
    case DATABASE = 'database';
    case DEPENDENCY_UPDATE = 'dependency-update';
    case SUBMIT_READY = 'submit-ready';
}
