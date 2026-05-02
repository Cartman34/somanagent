<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Service;

/**
 * A named CLI parameter (argument or option) as displayed in command help.
 */
final class CommandParamHelp
{
    public function __construct(
        public readonly string $name,
        public readonly string $description,
    ) {
    }
}
