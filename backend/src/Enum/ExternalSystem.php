<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Enum;

/**
 * External systems that can be linked to internal entities via external references.
 */
enum ExternalSystem: string
{
    case GitHub = 'github';
    case GitLab = 'gitlab';
    case Jira   = 'jira';
}
