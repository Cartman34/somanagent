<?php

declare(strict_types=1);

/**
 * Thrown when the scripts are run on Windows but WSL 2 is not installed
 * or not accessible via the `wsl` command.
 */
final class WslRequiredException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            "WSL 2 is required to run SoManAgent scripts on Windows.\n" .
            "  Install guide: https://learn.microsoft.com/en-us/windows/wsl/install"
        );
    }
}
