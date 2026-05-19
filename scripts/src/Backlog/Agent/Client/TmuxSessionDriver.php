<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Client;

use SoManAgent\Script\Backlog\Agent\Model\AgentSession;
use SoManAgent\Script\Console;

/**
 * Session driver backed by tmux.
 *
 * Each agent session lives in a tmux session named somanagent-<code>. Env vars are injected via a
 * temporary wrapper shell script so escaping is not a concern. The terminal is attached with
 * tmux attach-session and re-attached on resume. Stopping the session kills the tmux session and
 * all processes within it.
 *
 * SSH-resilient: an SSH disconnect does not terminate the running AI client because the tmux session
 * survives on the server. Reconnect by running resume.
 *
 * Mouse mode is enabled by default so the mouse wheel enters copy mode and allows scrolling through
 * the pane history. The scrollback buffer is extended to 50 000 lines. Both settings are applied
 * after session creation; failure is non-fatal and the session remains usable without them.
 *
 * Enabled with BACKLOG_AGENT_SESSION_DRIVER=tmux (default).
 */
final class TmuxSessionDriver implements SessionDriverInterface
{
    /**
     * Prefix used for all tmux session names managed by this driver.
     */
    private const SESSION_PREFIX = 'somanagent-';

    private ProcessRunner $shellRunner;
    private Console $console;

    /**
     * @param ProcessRunner $shellRunner Used to run tmux subcommands
     * @param Console $console For stop progress messages
     */
    public function __construct(ProcessRunner $shellRunner, Console $console)
    {
        $this->shellRunner = $shellRunner;
        $this->console = $console;
    }

    /**
     * {@inheritdoc}
     *
     * Checks that the tmux binary is available in PATH.
     */
    public function checkDependencies(): void
    {
        if (!$this->shellRunner->succeeds('command -v tmux')) {
            throw new \RuntimeException(
                "tmux is not installed or not available in PATH.\n" .
                "Run the setup installer to install it:\n" .
                "  php scripts/setup.php install\n" .
                "Or install it manually:\n" .
                "  Debian/Ubuntu : sudo apt-get install tmux\n" .
                "  macOS         : brew install tmux\n" .
                "Alternatively, switch to the direct driver: BACKLOG_AGENT_SESSION_DRIVER=direct",
            );
        }
    }

    /**
     * {@inheritdoc}
     *
     * Returns true when the tmux session somanagent-<code> already exists.
     */
    public function sessionExists(string $agentCode): bool
    {
        $name = $this->sessionName($agentCode);

        return $this->shellRunner->succeeds(sprintf('tmux has-session -t %s', escapeshellarg($name)));
    }

    /**
     * {@inheritdoc}
     *
     * A live tmux session can be resumed because resume re-attaches to the existing terminal.
     */
    public function allowsResumeWhileAlive(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     *
     * Creates a new detached tmux session, gets the pane PID, calls onSpawned, then attaches.
     * Throws when the tmux session somanagent-<code> already exists (use resume to reconnect).
     */
    public function launch(string $agentCode, string $bin, array $args, string $cwd, array $env, callable $onSpawned): int
    {
        $name = $this->sessionName($agentCode);

        if ($this->sessionExists($agentCode)) {
            throw new \RuntimeException(sprintf(
                "A tmux session '%s' already exists. Use start --code=%s to reconnect.",
                $name,
                $agentCode,
            ));
        }

        $wrapperPath = $this->writeWrapperScript($bin, $args, $env);

        try {
            $this->createSession($name, $agentCode, $wrapperPath, $cwd);
            $panePid = $this->getPanePid($name);
            $onSpawned($panePid, $name);

            return $this->attachAndWait($name);
        } finally {
            @unlink($wrapperPath);
        }
    }

    /**
     * {@inheritdoc}
     *
     * Re-attaches to an existing tmux session when it is still alive. Otherwise creates a new
     * session and launches the client with the resume args already embedded in $args.
     */
    public function resume(string $agentCode, string $bin, array $args, string $cwd, array $env, callable $onSpawned): int
    {
        $name = $this->sessionName($agentCode);

        if ($this->shellRunner->succeeds(sprintf('tmux has-session -t %s', escapeshellarg($name)))) {
            // Existing tmux session: re-attach without re-launching.
            $panePid = $this->getPanePid($name);
            $onSpawned($panePid, $name);

            return $this->attachAndWait($name);
        }

        // No tmux session: create a new one and launch the client in resume mode.
        $wrapperPath = $this->writeWrapperScript($bin, $args, $env);

        try {
            $this->createSession($name, $agentCode, $wrapperPath, $cwd);
            $panePid = $this->getPanePid($name);
            $onSpawned($panePid, $name);

            return $this->attachAndWait($name);
        } finally {
            @unlink($wrapperPath);
        }
    }

    /**
     * {@inheritdoc}
     *
     * Kills the tmux session recorded in session->tmuxSession via tmux kill-session.
     */
    public function stop(AgentSession $session): void
    {
        $tmuxSession = $session->tmuxSession;

        if ($tmuxSession === null || $tmuxSession === '') {
            $this->console->warn(sprintf(
                'Session %s has no recorded tmux session name; only the session entry will be removed.',
                $session->code,
            ));

            return;
        }

        $this->console->line(sprintf("Killing tmux session '%s'...", $tmuxSession));
        $this->shellRunner->succeeds(sprintf('tmux kill-session -t %s', escapeshellarg($tmuxSession)));
    }

    /**
     * {@inheritdoc}
     *
     * Returns true when session->tmuxSession exists in the tmux server.
     * Returns false when tmuxSession is null (session was created by the direct driver).
     */
    public function isAlive(AgentSession $session): bool
    {
        $tmuxSession = $session->tmuxSession;

        if ($tmuxSession === null || $tmuxSession === '') {
            return false;
        }

        return $this->shellRunner->succeeds(sprintf('tmux has-session -t %s', escapeshellarg($tmuxSession)));
    }

    /**
     * {@inheritdoc}
     *
     * Runs tmux list-sessions and returns codes extracted from somanagent-<code> session names.
     * Sessions whose names do not carry the somanagent- prefix are silently ignored.
     * Returns an empty array when no tmux server is running or when no managed sessions exist.
     *
     * @return list<string>
     */
    public function listLiveSessions(): array
    {
        $output = $this->shellRunner->output("tmux list-sessions -F '#{session_name}'");
        if ($output === null || $output === '') {
            return [];
        }

        $codes = [];
        foreach (explode("\n", trim($output)) as $line) {
            $line = trim($line);
            if ($line === '' || !str_starts_with($line, self::SESSION_PREFIX)) {
                continue;
            }
            $codes[] = substr($line, strlen(self::SESSION_PREFIX));
        }

        return $codes;
    }

    /**
     * {@inheritdoc}
     *
     * Kills the tmux session somanagent-<code> via tmux kill-session.
     */
    public function kill(string $agentCode): void
    {
        $name = $this->sessionName($agentCode);
        $this->console->line(sprintf("Killing orphan tmux session '%s'...", $name));
        $this->shellRunner->succeeds(sprintf('tmux kill-session -t %s', escapeshellarg($name)));
    }

    /**
     * Returns the tmux session name for the given agent code.
     */
    private function sessionName(string $agentCode): string
    {
        return self::SESSION_PREFIX . $agentCode;
    }

    /**
     * Writes a temporary shell wrapper script that exports env vars and execs the client binary.
     *
     * Returns the absolute path to the written script.
     *
     * @param list<string> $args
     * @param array<string, string> $env
     */
    private function writeWrapperScript(string $bin, array $args, array $env): string
    {
        $path = sys_get_temp_dir() . '/backlog-agent-' . uniqid('', true) . '.sh';

        $lines = ['#!/bin/sh'];
        foreach ($env as $key => $value) {
            $lines[] = 'export ' . $key . '=' . escapeshellarg($value);
        }

        $parts = array_map('escapeshellarg', [$bin, ...$args]);
        $lines[] = 'exec ' . implode(' ', $parts);

        file_put_contents($path, implode("\n", $lines) . "\n");
        chmod($path, 0700);

        return $path;
    }

    /**
     * Creates a detached tmux session running the wrapper script in the given working directory.
     *
     * After the session is created, mouse mode, an extended scrollback buffer, and the Sowapps
     * status-line branding are applied via session-targeted tmux options. The AI client runs in
     * alt-screen mode, which bypasses the host terminal scrollback; mouse mode enables wheel-scroll
     * in the pane via tmux copy mode. These options are ergonomic improvements; if any set call
     * fails, a warning is emitted and the session continues without that option.
     */
    private function createSession(string $name, string $agentCode, string $wrapperPath, string $cwd): void
    {
        $command = sprintf(
            'tmux new-session -d -s %s -c %s %s',
            escapeshellarg($name),
            escapeshellarg($cwd),
            escapeshellarg($wrapperPath),
        );

        if (!$this->shellRunner->succeeds($command)) {
            throw new \RuntimeException(sprintf("Failed to create tmux session '%s'.", $name));
        }

        if (!$this->shellRunner->succeeds(sprintf('tmux set-option -t %s mouse on', escapeshellarg($name)))) {
            $this->console->warn(sprintf("tmux set-option mouse on failed for session '%s'; mouse scrollback will not be available.", $name));
        }
        if (!$this->shellRunner->succeeds(sprintf('tmux set-option -t %s history-limit 50000', escapeshellarg($name)))) {
            $this->console->warn(sprintf("tmux set-option history-limit failed for session '%s'; scrollback history uses the tmux default.", $name));
        }

        $this->applySowappsStatusLine($name, $agentCode);
    }

    /**
     * Applies the one-line Sowapps tmux status-line branding to this session only.
     */
    private function applySowappsStatusLine(string $name, string $agentCode): void
    {
        $options = [
            'status' => 'on',
            'status-style' => 'bg=colour202,fg=colour231',
            'status-left-length' => '20',
            'status-right-length' => '50',
            'status-left' => '#[fg=colour202,bg=colour231] SOWAPPS #[default] ',
            'status-right' => sprintf('#[fg=colour202,bg=colour231] %s #[default] %%Y-%%m-%%d %%H:%%M:%%S ', $agentCode),
            'window-status-format' => ' #W ',
            'window-status-current-format' => ' #W ',
            'window-status-style' => 'bg=colour202,fg=colour231',
            'window-status-current-style' => 'bg=terminal,fg=colour231,bold',
            'window-status-separator' => '',
        ];

        foreach ($options as $option => $value) {
            $command = sprintf(
                'tmux set -t %s %s %s',
                escapeshellarg($name),
                $option,
                escapeshellarg($value),
            );

            if (!$this->shellRunner->succeeds($command)) {
                $this->console->warn(sprintf(
                    "tmux set %s failed for session '%s'; Sowapps status-line branding may be incomplete.",
                    $option,
                    $name,
                ));
            }
        }
    }

    /**
     * Returns the PID of the foreground process in the tmux session's active pane.
     *
     * The format string `#{pane_pid}` must be wrapped in literal single quotes so the
     * shell does not strip it: `#` introduces a comment in /bin/sh, sh -c and the
     * subprocess executors used by `proc_open`. Without the quotes, the shell silently
     * truncates the command at `#`, tmux receives `-p` with no format and prints its
     * default summary line, the parse returns 0, and the launch fails with an opaque
     * "Could not determine pane PID" error.
     */
    private function getPanePid(string $name): int
    {
        $output = $this->shellRunner->output(
            sprintf("tmux display-message -t %s -p '#{pane_pid}'", escapeshellarg($name)),
        );

        $pid = (int) trim((string) $output);
        if ($pid <= 0) {
            throw new \RuntimeException(sprintf(
                "Could not determine pane PID for tmux session '%s'.",
                $name,
            ));
        }

        return $pid;
    }

    /**
     * Attaches the current terminal to the tmux session and blocks until attach-session exits.
     *
     * Returns 0 whether the session ended normally or the terminal was detached (Ctrl+B D, SSH
     * disconnect). The client's exit code is not recoverable through tmux attach. Callers must
     * call sessionExists() after this returns to distinguish a detach (session alive) from a
     * normal termination (session gone).
     */
    private function attachAndWait(string $name): int
    {
        // proc_open with stdio attached to the current terminal, same as SystemInteractiveProcessRunner.
        $command = ['tmux', 'attach-session', '-t', $name];
        $descriptors = [0 => STDIN, 1 => STDOUT, 2 => STDERR];
        $pipes = [];

        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException(sprintf("Failed to attach to tmux session '%s'.", $name));
        }

        $exitCode = $this->waitForExit($process);
        proc_close($process);

        return $exitCode;
    }

    /**
     * Polls proc_get_status until the process exits and returns its exit code.
     *
     * @param resource $process
     */
    private function waitForExit($process): int
    {
        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                return (int) $status['exitcode'];
            }
            usleep(200_000);
        }
    }
}
