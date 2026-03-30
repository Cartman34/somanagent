<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

/**
 * Detects the current runtime environment (Windows, WSL, Linux, macOS)
 * and provides path conversion utilities.
 */
final class Environment
{
    // ── OS detection ──────────────────────────────────────────────────────────

    public static function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    /**
     * Returns true when running inside WSL (Windows Subsystem for Linux).
     * Works for both WSL 1 and WSL 2.
     */
    public static function isWsl(): bool
    {
        // WSL always sets this environment variable
        if (!empty(getenv('WSL_DISTRO_NAME'))) {
            return true;
        }

        // Fallback: kernel version string contains 'microsoft'
        if (PHP_OS_FAMILY === 'Linux') {
            $version = @file_get_contents('/proc/version');
            if ($version && stripos($version, 'microsoft') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns true when the script is running on Windows outside of WSL
     * and should be re-executed inside WSL.
     */
    public static function needsWslRedirect(): bool
    {
        return self::isWindows() && !self::isWsl();
    }

    // ── Path utilities ────────────────────────────────────────────────────────

    /**
     * Converts a Windows absolute path to its WSL mount equivalent.
     *
     *   C:\Users\foo\bar  →  /mnt/c/Users/foo/bar
     */
    public static function toWslPath(string $windowsPath): string
    {
        $path = str_replace('\\', '/', $windowsPath);

        return preg_replace_callback(
            '/^([A-Za-z]):/',
            static fn(array $m) => '/mnt/' . strtolower($m[1]),
            $path
        );
    }

    // ── Filesystem ────────────────────────────────────────────────────────────

    /**
     * Returns true when running inside WSL but the current working directory
     * is on the Windows NTFS filesystem (/mnt/c/…, /mnt/d/…, etc.).
     *
     * This matters because Docker bind mounts from /mnt/* go through the 9P
     * filesystem protocol over the Hyper-V virtio channel, which is 5-20x
     * slower than WSL's native ext4 filesystem. Moving the project to ~/…
     * eliminates the overhead and brings Docker I/O to native Linux speed.
     */
    public static function isOnWindowsFilesystem(): bool
    {
        if (!self::isWsl()) {
            return false;
        }

        $cwd = getcwd();
        // /mnt/c/, /mnt/d/, etc. are Windows NTFS drives mounted in WSL
        return $cwd !== false && (bool) preg_match('#^/mnt/[a-z]/#', $cwd);
    }

    // ── WSL availability ──────────────────────────────────────────────────────

    /**
     * Returns true if the `wsl` command is available and at least one
     * WSL 2 distribution is registered.
     */
    public static function wslAvailable(): bool
    {
        exec('wsl -l -v 2>&1', $out, $code);
        return $code === 0;
    }

    /**
     * Returns true if PHP is available inside WSL.
     */
    public static function phpAvailableInWsl(): bool
    {
        exec('wsl php --version 2>&1', $out, $code);
        return $code === 0;
    }
}
