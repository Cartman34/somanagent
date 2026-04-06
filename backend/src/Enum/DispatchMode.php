<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Enum;

/**
 * Dispatch mode controlling whether tasks are automatically or manually triggered.
 */
enum DispatchMode: string
{
    case Auto = 'auto';
    case Manual = 'manual';
}
