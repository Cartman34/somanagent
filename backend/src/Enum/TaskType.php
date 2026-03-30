<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Enum;

enum TaskType: string
{
    case UserStory = 'user_story';
    case Bug       = 'bug';
    case Task      = 'task';
}
