<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Enum;

enum FeatureStatus: string
{
    case Open       = 'open';
    case InProgress = 'in_progress';
    case Closed     = 'closed';
}
