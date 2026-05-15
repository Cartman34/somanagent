<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Client;

use SoManAgent\Script\Backlog\Agent\Model\AgentSession;
use SoManAgent\Script\Console;

/**
 * Session driver backed by proc_open — the pre-tmux behaviour.
 *
 * Degraded mode: no SSH resilience. Resume re-launches the client CLI with the stored session ID
 * but cannot re-attach to the original terminal session. stop() targets the recorded client PID
 * (or wrapper PID as fallback) via SIGTERM → SIGKILL.
 *
 * Enabled with BACKLOG_AGENT_SESSION_DRIVER=direct.
 */
final class DirectSessionDriver implements SessionDriverInterface
{
    /**
     * Default grace period (in seconds) between SIGTERM and the SIGKILL follow-up.
     */
    public const DEFAULT_TERMINATION_GRACE_SECONDS = 5;

    /**
     * Poll interval (in microseconds) used while waiting for the client to acknowledge SIGTERM.
     */
    private const POLL_USEC = 200_000;

    private InteractiveProcessRunner $processRunner;
    private ProcessSignaler $signaler;
    private Console $console;
    private int $terminationGraceSeconds;

    /**
     * @param InteractiveProcessRunner $processRunner Underlying proc_open runner
     * @param ProcessSignaler $signaler Used to send signals and check process liveness
     * @param Console $console For stop progress messages
     * @param int $terminationGraceSeconds Seconds between SIGTERM and SIGKILL
     */
    public function __construct(
        InteractiveProcessRunner $processRunner,
        ProcessSignaler $signaler,
        Console $console,
        int $terminationGraceSeconds = self::DEFAULT_TERMINATION_GRACE_SECONDS,
    ) {
        $this->processRunner = $processRunner;
        $this->signaler = $signaler;
        $this->console = $console;
        $this->terminationGraceSeconds = $terminationGraceSeconds;
    }

    /**
     * {@inheritdoc}
     *
     * proc_open is a PHP built-in — no external dependency to check.
     */
    public function checkDependencies(): void
    {
        // No external dependencies required for the direct driver.
    }

    /**
     * {@inheritdoc}
     *
     * The direct driver has no persistent session concept — always returns false.
     */
    public function sessionExists(string $agentCode): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     *
     * A live direct session means a client process is still running, so resume must refuse.
     */
    public function allowsResumeWhileAlive(): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function launch(string $agentCode, string $bin, array $args, string $cwd, array $env, callable $onSpawned): int
    {
        return $this->run($bin, $args, $cwd, $env, $onSpawned);
    }

    /**
     * {@inheritdoc}
     *
     * For the direct driver, resume is identical to launch: proc_open re-executes the binary with
     * the resume flags already present in $args. No live terminal session can be re-attached.
     */
    public function resume(string $agentCode, string $bin, array $args, string $cwd, array $env, callable $onSpawned): int
    {
        return $this->run($bin, $args, $cwd, $env, $onSpawned);
    }

    /**
     * {@inheritdoc}
     *
     * Sends SIGTERM to the recorded client PID (or wrapper PID), waits up to terminationGraceSeconds,
     * then follows up with SIGKILL when the process did not exit.
     */
    public function stop(AgentSession $session): void
    {
        $target = $this->resolveSignalTarget($session);

        if ($target === null) {
            $this->console->warn(sprintf(
                'Session %s has no recorded client PID; only the session entry will be removed.',
                $session->code,
            ));

            return;
        }

        [$signalPid, $aliveCheckPid, $label] = $target;
        $this->console->line(sprintf('Sending SIGTERM to %s...', $label));
        $this->signaler->signal($signalPid, SIGTERM);

        $deadline = time() + $this->terminationGraceSeconds;
        while ($this->signaler->isAlive($aliveCheckPid) && time() < $deadline) {
            usleep(self::POLL_USEC);
        }

        if ($this->signaler->isAlive($aliveCheckPid)) {
            $this->console->warn(sprintf(
                '%s still running after %ds, sending SIGKILL.',
                $label,
                $this->terminationGraceSeconds,
            ));
            $this->signaler->signal($signalPid, SIGKILL);
        }
    }

    /**
     * {@inheritdoc}
     *
     * Checks session->clientPid first, then session->pid via ProcessSignaler.
     */
    public function isAlive(AgentSession $session): bool
    {
        if ($session->clientPid !== null && $session->clientPid > 0 && $this->signaler->isAlive($session->clientPid)) {
            return true;
        }

        return $session->pid > 0 && $this->signaler->isAlive($session->pid);
    }

    /**
     * Delegates to the underlying process runner, adapting the onSpawned callback signature.
     *
     * InteractiveProcessRunner calls back with (clientPid). This driver
     * forwards (clientPid, null) to the SessionDriverInterface caller: the direct driver
     * never populates a tmux session name.
     *
     * @param list<string> $args
     * @param array<string, string> $env
     */
    private function run(string $bin, array $args, string $cwd, array $env, callable $onSpawned): int
    {
        $result = $this->processRunner->run(
            $bin,
            $args,
            $cwd,
            $env,
            static function (int $clientPid) use ($onSpawned): void {
                $onSpawned($clientPid, null);
            },
        );

        return $result->exitCode;
    }

    /**
     * Picks the best signal target: client PID, then wrapper PID.
     *
     * Returns [signalPid, aliveCheckPid, label] or null when nothing is signalable.
     *
     * @return array{0: int, 1: int, 2: string}|null
     */
    private function resolveSignalTarget(AgentSession $session): ?array
    {
        if ($session->clientPid !== null && $session->clientPid > 0) {
            return [
                $session->clientPid,
                $session->clientPid,
                sprintf('client PID %d', $session->clientPid),
            ];
        }

        if ($session->pid > 0) {
            return [
                $session->pid,
                $session->pid,
                sprintf('wrapper PID %d', $session->pid),
            ];
        }

        return null;
    }
}
