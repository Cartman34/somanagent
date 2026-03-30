<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

require_once __DIR__ . '/Environment.php';
require_once __DIR__ . '/Console.php';
require_once __DIR__ . '/Exception/WslRequiredException.php';
require_once __DIR__ . '/Exception/PhpNotAvailableException.php';

/**
 * Main application entry point for SoManAgent CLI scripts.
 *
 * Responsibilities:
 *  1. Create and expose a Console instance (line endings auto-detected).
 *  2. On Windows: transparently re-execute the calling script inside WSL 2.
 *  3. Provide runCommand() so that subprocess output also gets the CRLF
 *     conversion — preventing the staircase effect in Windows terminals when
 *     docker compose / bash subprocesses write bare \n through the WSL pipe.
 *
 * Typical usage:
 *
 *   require_once __DIR__ . '/src/Application.php';
 *
 *   try {
 *       $app = new Application();
 *       $app->boot();
 *   } catch (\RuntimeException $e) {
 *       fwrite(STDERR, "\n❌ " . $e->getMessage() . "\n\n");
 *       exit(1);
 *   }
 *
 *   $c = $app->console;
 *   $c->step('Building containers');
 *   $app->runCommand('docker compose up -d --build');
 */
final class Application
{
    public readonly Console $console;

    /** True when running in WSL with stdout piped to a Windows process. */
    private readonly bool $crlfMode;

    public function __construct()
    {
        $this->console  = new Console();
        // Console is the single source of truth for CRLF detection
        $this->crlfMode = $this->console->usesCrlf();
    }

    /**
     * Bootstrap the environment.
     *
     * On Linux / macOS / WSL: returns immediately.
     * On Windows: validates WSL 2 + PHP, then re-executes inside WSL (never returns).
     *
     * @throws WslRequiredException     WSL 2 is not installed or unavailable.
     * @throws PhpNotAvailableException PHP is not installed inside WSL.
     */
    public function boot(): void
    {
        if (!Environment::needsWslRedirect()) {
            return;
        }

        $this->assertWslAvailable();
        $this->assertPhpInWsl();
        $this->rerunInWsl();
    }

    /**
     * Run a shell command, printing its output to the terminal.
     *
     * In normal mode (Linux TTY, direct WSL terminal): delegates to passthru()
     * so the subprocess inherits a real TTY and can use colours / progress bars.
     *
     * In CRLF mode (WSL stdout piped to Windows terminal): captures the
     * subprocess output through popen() and converts every lone \n to \r\n
     * before printing. This fixes the staircase effect for all subprocesses
     * (docker compose, npm, bash scripts, …) not just PHP's own echo calls.
     *
     * Both stdout and stderr of the command are forwarded (2>&1 merge).
     *
     * @throws \RuntimeException if the pipe cannot be opened.
     */
    public function runCommand(string $cmd): int
    {
        if (!$this->crlfMode) {
            passthru($cmd, $exitCode);
            return $exitCode;
        }

        // Merge stderr into stdout so both streams get CRLF conversion.
        $handle = popen($cmd . ' 2>&1', 'r');
        if ($handle === false) {
            throw new \RuntimeException("Failed to open pipe for: $cmd");
        }

        // Read in chunks, flush complete lines in real time.
        $pending = '';
        while (!feof($handle)) {
            $chunk = fread($handle, 1024);
            if ($chunk === false || $chunk === '') {
                continue;
            }
            $pending .= $chunk;

            // Flush everything up to the last newline so partial lines
            // (e.g. docker progress overwrite sequences using \r) stay buffered
            // until the full line is available.
            $lastNl = strrpos($pending, "\n");
            if ($lastNl !== false) {
                $toFlush = substr($pending, 0, $lastNl + 1);
                $pending = substr($pending, $lastNl + 1);
                echo $this->toCrlf($toFlush);
                flush();
            }
        }

        // Flush any remaining bytes (line without trailing newline)
        if ($pending !== '') {
            echo $this->toCrlf($pending);
            flush();
        }

        $exitCode = pclose($handle);
        return $exitCode >= 0 ? $exitCode : 1;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Converts lone \n (not already preceded by \r) to \r\n.
     * Leaves existing \r\n sequences and bare \r (progress overwrites) intact.
     */
    private function toCrlf(string $text): string
    {
        return preg_replace('/(?<!\r)\n/', "\r\n", $text);
    }

    /** @throws WslRequiredException */
    private function assertWslAvailable(): void
    {
        if (!Environment::wslAvailable()) {
            throw new WslRequiredException();
        }
    }

    /** @throws PhpNotAvailableException */
    private function assertPhpInWsl(): void
    {
        if (!Environment::phpAvailableInWsl()) {
            throw new PhpNotAvailableException();
        }
    }

    /**
     * Converts the Windows path of the calling script to /mnt/… and
     * re-executes it inside WSL, then exits with WSL's return code.
     * Never returns.
     */
    private function rerunInWsl(): never
    {
        $scriptAbsolute = (string) realpath($GLOBALS['argv'][0]);
        $wslScript      = Environment::toWslPath($scriptAbsolute);

        $args    = array_map('escapeshellarg', array_slice($GLOBALS['argv'], 1));
        $argsStr = implode(' ', $args);

        passthru("wsl php $wslScript $argsStr", $exitCode);
        exit($exitCode);
    }
}
