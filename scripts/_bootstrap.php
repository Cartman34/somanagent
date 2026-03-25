<?php
/**
 * Bootstrap included by every SoManAgent script.
 *
 * On Windows: verifies WSL 2 is available, then re-executes the calling
 * script transparently inside WSL so that all shell commands use bash.
 *
 * On Linux/macOS or inside WSL: no-op.
 */

if (!function_exists('_sma_is_wsl')) {

    function _sma_is_wsl(): bool
    {
        // WSL sets WSL_DISTRO_NAME in the environment
        if (!empty(getenv('WSL_DISTRO_NAME'))) {
            return true;
        }
        // Fallback: read kernel version (contains 'microsoft' in WSL)
        if (PHP_OS_FAMILY === 'Linux') {
            $version = @file_get_contents('/proc/version');
            if ($version && stripos($version, 'microsoft') !== false) {
                return true;
            }
        }
        return false;
    }

    function _sma_windows_to_wsl_path(string $path): string
    {
        // C:\foo\bar  →  /mnt/c/foo/bar
        $path = str_replace('\\', '/', $path);
        return preg_replace_callback(
            '/^([A-Za-z]):/',
            fn($m) => '/mnt/' . strtolower($m[1]),
            $path
        );
    }

    function _sma_bootstrap(): void
    {
        // Already inside WSL or running on Linux/macOS — nothing to do
        if (PHP_OS_FAMILY !== 'Windows' || _sma_is_wsl()) {
            return;
        }

        // ── Check WSL 2 is installed ──────────────────────────────────────
        exec('wsl -l -v 2>&1', $wslList, $wslCode);
        if ($wslCode !== 0) {
            fwrite(STDERR, "\n❌ WSL 2 is required to run SoManAgent scripts on Windows.\n");
            fwrite(STDERR, "   Install WSL 2: https://learn.microsoft.com/en-us/windows/wsl/install\n\n");
            exit(1);
        }

        // ── Check PHP is available inside WSL ─────────────────────────────
        exec('wsl php --version 2>&1', $phpOut, $phpCode);
        if ($phpCode !== 0) {
            fwrite(STDERR, "\n❌ PHP is not available inside WSL.\n");
            fwrite(STDERR, "   Run the following to install PHP 8.4:\n");
            fwrite(STDERR, "   wsl bash scripts/check-php.sh\n\n");
            exit(1);
        }

        // ── Re-run this script transparently inside WSL ───────────────────
        $scriptAbsolute = realpath($GLOBALS['argv'][0]);
        $wslScript      = _sma_windows_to_wsl_path($scriptAbsolute);

        $args    = array_map('escapeshellarg', array_slice($GLOBALS['argv'], 1));
        $argsStr = implode(' ', $args);

        passthru("wsl php $wslScript $argsStr", $exitCode);
        exit($exitCode);
    }

    _sma_bootstrap();
}
