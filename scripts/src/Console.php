<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script;

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
    private static ?self $instance = null;

    private string $eol;

    private function __construct()
    {
        $this->eol = (Environment::isWsl() && !$this->isTty()) ? "\r\n" : "\n";
    }

    /**
     * @return self
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ── Output primitives ─────────────────────────────────────────────────────

    /**
     * Print a bare line (or blank line).
     */
    public function line(string $text = ''): void
    {
        echo $text . $this->eol;
    }

    // ── Semantic helpers (used by scripts) ────────────────────────────────────

    /**
     * ▶ Step heading
     */
    public function step(string $label): void
    {
        $this->line();
        $this->line("▶ $label...");
    }

    /**
     * ✓ Success message
     */
    public function ok(string $msg): void
    {
        $this->line("  ✓ $msg");
    }

    /**
     * → Informational note
     */
    public function info(string $msg): void
    {
        $this->line("  → $msg");
    }

    /**
     * ⚠ Warning (non-fatal)
     */
    public function warn(string $msg): void
    {
        $this->line("  ⚠  $msg");
    }

    /**
     * ❌ Fatal error — prints message and exits.
     */
    public function fail(string $msg, int $code = 1): never
    {
        $this->line("  ❌ $msg");
        exit($code);
    }

    // ── Input ─────────────────────────────────────────────────────────────────

    /**
     * Asks the operator to confirm a destructive action by typing "yes".
     *
     * Prints the warning and the confirmation hint, then reads one line from
     * STDIN. Returns true only when the operator typed "yes" (case-insensitive,
     * surrounding whitespace ignored). Returns false when STDIN is not an
     * interactive terminal (piped, closed, CI), so callers that need a
     * non-interactive path must offer an explicit force flag.
     *
     * @param string $warning Human-readable description of what is about to happen
     * @return bool True when the operator confirmed with "yes"
     */
    public function confirm(string $warning): bool
    {
        if (!stream_isatty(STDIN)) {
            return false;
        }

        $this->warn($warning);
        $this->line('  Type "yes" to continue:');

        return strtolower(trim((string) fgets(STDIN))) === 'yes';
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
