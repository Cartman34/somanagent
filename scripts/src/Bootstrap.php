<?php

declare(strict_types=1);

require_once __DIR__ . '/Environment.php';

/**
 * Entry point included at the top of every public script.
 *
 * On Windows (outside WSL):
 *   1. Verifies WSL 2 is installed — exits with guidance if not.
 *   2. Verifies PHP is available in WSL — exits with guidance if not.
 *   3. Converts the script path to its /mnt/... equivalent and
 *      re-executes it transparently via `wsl php ...`.
 *
 * On WSL / Linux / macOS: pure no-op.
 *
 * Usage in every script (right after the header block):
 *   require_once __DIR__ . '/src/Bootstrap.php';
 */
final class Bootstrap
{
    public static function init(): void
    {
        if (!Environment::needsWslRedirect()) {
            return;
        }

        self::assertWslAvailable();
        self::assertPhpInWsl();
        self::rerunInWsl();
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private static function assertWslAvailable(): void
    {
        if (Environment::wslAvailable()) {
            return;
        }

        fwrite(STDERR, "\n❌ WSL 2 is required to run SoManAgent scripts on Windows.\n");
        fwrite(STDERR, "   Install WSL 2: https://learn.microsoft.com/en-us/windows/wsl/install\n\n");
        exit(1);
    }

    private static function assertPhpInWsl(): void
    {
        if (Environment::phpAvailableInWsl()) {
            return;
        }

        fwrite(STDERR, "\n❌ PHP is not available inside WSL.\n");
        fwrite(STDERR, "   Run the following command to install PHP 8.4:\n");
        fwrite(STDERR, "   wsl bash scripts/check-php.sh\n\n");
        exit(1);
    }

    /**
     * Re-runs the calling script inside WSL and exits with its return code.
     * Never returns.
     */
    private static function rerunInWsl(): never
    {
        $scriptAbsolute = (string) realpath($GLOBALS['argv'][0]);
        $wslScript      = Environment::toWslPath($scriptAbsolute);

        $args    = array_map('escapeshellarg', array_slice($GLOBALS['argv'], 1));
        $argsStr = implode(' ', $args);

        passthru("wsl php $wslScript $argsStr", $exitCode);
        exit($exitCode);
    }
}

Bootstrap::init();
