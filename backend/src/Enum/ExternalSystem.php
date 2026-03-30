<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Enum;

enum ExternalSystem: string
{
    case GitHub = 'github';
    case GitLab = 'gitlab';
    case Jira   = 'jira';
}
