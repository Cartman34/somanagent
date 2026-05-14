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
                "Install it before launching agents in tmux mode:\n" .
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
     * Creates a new detached tmux session, gets the pane PID, calls onSpawned, then attaches.
     * Throws when the tmux session somanagent-<code> already exists (use resume to reconnect).
     */
    public function launch(string $agentCode, string $bin, array $args, string $cwd, array $env, callable $onSpawned): int
    {
        $name = $this->sessionName($agentCode);

        if ($this->sessionExists($agentCode)) {
            throw new \RuntimeException(sprintf(
                "A tmux session '%s' already exists. Use resume to reconnect:\n" .
                "  php scripts/backlog-agent.php resume --code=%s",
                $name,
                $agentCode,
            ));
        }

        $wrapperPath = $this->writeWrapperScript($bin, $args, $env);

        try {
            $this->createSession($name, $wrapperPath, $cwd);
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
            $this->createSession($name, $wrapperPath, $cwd);
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
     */
    private function createSession(string $name, string $wrapperPath, string $cwd): void
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
    }

    /**
     * Returns the PID of the foreground process in the tmux session's active pane.
     */
    private function getPanePid(string $name): int
    {
        $output = $this->shellRunner->output(
            sprintf('tmux display-message -t %s -p #{pane_pid}', escapeshellarg($name)),
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
     * Attaches the current terminal to the tmux session and blocks until it ends.
     *
     * Returns 0 when the session ends normally. The client's exit code is not recoverable
     * through tmux attach — callers should treat 0 as success.
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
