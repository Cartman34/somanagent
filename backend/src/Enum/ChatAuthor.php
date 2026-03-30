<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Enum;

enum ChatAuthor: string
{
    case Human = 'human';
    case Agent = 'agent';
}
