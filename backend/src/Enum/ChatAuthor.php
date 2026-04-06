<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Enum;

/**
 * Identifies the author of a chat message: human user or agent.
 */
enum ChatAuthor: string
{
    case Human = 'human';
    case Agent = 'agent';
}
