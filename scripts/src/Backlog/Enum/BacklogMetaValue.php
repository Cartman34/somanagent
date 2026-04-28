<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Enum;

/**
 * Stable metadata values used in backlog board.
 */
enum BacklogMetaValue: string
{
    case YES = 'yes';
    case NONE = 'none';
}
