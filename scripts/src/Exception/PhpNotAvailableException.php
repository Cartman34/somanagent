<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

/**
 * Thrown when PHP is not available inside WSL, preventing the
 * transparent WSL redirect from working.
 */
final class PhpNotAvailableException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            "PHP is not available inside WSL.\n" .
            "  Run: wsl bash scripts/check-php.sh"
        );
    }
}
