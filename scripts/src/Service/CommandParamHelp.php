<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Service;

/**
 * A named CLI parameter (argument or option) as displayed in command help.
 */
final class CommandParamHelp
{
    /**
     * @param string $name        Display name of the parameter (e.g. "--agent" or "<text>")
     * @param string $description Human-readable description shown in command help output
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
    ) {
    }
}
