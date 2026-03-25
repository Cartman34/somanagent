<?php

declare(strict_types=1);

require_once __DIR__ . '/Environment.php';

/**
 * Terminal output helper used by all SoManAgent scripts.
 *
 * Automatically switches to CRLF (\r\n) when running inside WSL with
 * stdout piped to a Windows process (e.g. `wsl php script.php` from
 * cmd.exe / PowerShell / Git Bash). This prevents the "staircase" effect
 * where bare \n moves the cursor down but not back to column 0.
 *
 * When stdout is a real TTY (WSL terminal, Linux, macOS) plain \n is used.
 *
 * Usage:
 *   $console = Bootstrap::init();   // Console is created there
 *   $console->step('Doing something');
 *   $console->ok('Done');
 */
final class Console
{
    private string $eol;

    public function __construct()
    {
        // Use CRLF when: inside WSL AND stdout is not a TTY
        // (stdout is a pipe → output is consumed by a Windows terminal)
        $this->eol = (Environment::isWsl() && !$this->isTty()) ? "\r\n" : "\n";
    }

    // ── Output primitives ─────────────────────────────────────────────────────

    /** Print a bare line (or blank line). */
    public function line(string $text = ''): void
    {
        echo $text . $this->eol;
    }

    /** Horizontal rule: ══════════════ */
    public function hr(int $width = 50, string $char = '═'): void
    {
        $this->line(str_repeat($char, $width));
    }

    /** Thin horizontal rule: ────────── */
    public function separator(int $width = 50): void
    {
        $this->hr($width, '─');
    }

    // ── Semantic helpers (used by scripts) ────────────────────────────────────

    /** ▶ Step heading */
    public function step(string $label): void
    {
        $this->line();
        $this->line("▶ $label...");
    }

    /** ✓ Success message */
    public function ok(string $msg): void
    {
        $this->line("  ✓ $msg");
    }

    /** → Informational note */
    public function info(string $msg): void
    {
        $this->line("  → $msg");
    }

    /** ⚠ Warning (non-fatal) */
    public function warn(string $msg): void
    {
        $this->line("  ⚠  $msg");
    }

    /** ❌ Fatal error — prints message and exits. */
    public function fail(string $msg, int $code = 1): never
    {
        $this->line("  ❌ $msg");
        exit($code);
    }

    // ── Introspection ─────────────────────────────────────────────────────────

    /**
     * Returns true when CRLF mode is active (WSL with piped stdout).
     * Used by Application to apply the same conversion to subprocess output.
     */
    public function usesCrlf(): bool
    {
        return $this->eol === "\r\n";
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function isTty(): bool
    {
        if (function_exists('posix_isatty')) {
            return posix_isatty(STDOUT);
        }
        // posix not available (should not happen on WSL/Linux) — assume TTY
        return true;
    }
}
