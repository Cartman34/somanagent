<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Command;

use SoManAgent\Script\Backlog\Agent\Client\ProcessSignaler;
use SoManAgent\Script\Backlog\Agent\Model\AgentSession;
use SoManAgent\Script\Backlog\Agent\Service\AgentSessionService;
use SoManAgent\Script\Console;

/**
 * Stops a live agent session or cleans up a stale entry.
 *
 * Usage:
 *   php scripts/backlog-agent.php stop --code=<code> [--cleanup]
 */
final class AgentStopCommand extends AbstractAgentCommand
{
    /**
     * Default grace period (in seconds) between SIGTERM and the SIGKILL follow-up.
     */
    public const DEFAULT_TERMINATION_GRACE_SECONDS = 5;

    /**
     * Poll interval (in microseconds) used while waiting for the client to acknowledge SIGTERM.
     */
    private const POLL_USEC = 200_000;

    private Console $console;
    private AgentSessionService $sessionService;
    private ProcessSignaler $signaler;
    private int $terminationGraceSeconds;

    /**
     * @param Console $console
     * @param AgentSessionService $sessionService
     * @param ProcessSignaler $signaler
     * @param int $terminationGraceSeconds Maximum seconds between SIGTERM and SIGKILL
     */
    public function __construct(
        Console $console,
        AgentSessionService $sessionService,
        ProcessSignaler $signaler,
        int $terminationGraceSeconds = self::DEFAULT_TERMINATION_GRACE_SECONDS,
    ) {
        $this->console = $console;
        $this->sessionService = $sessionService;
        $this->signaler = $signaler;
        $this->terminationGraceSeconds = $terminationGraceSeconds;
    }

    /**
     * {@inheritdoc}
     */
    public function getDescription(): string
    {
        return 'Stop a live agent session or remove a stale session entry';
    }

    /**
     * {@inheritdoc}
     */
    public function getOptions(): array
    {
        return [
            ['name' => '--code=<code>', 'description' => 'Agent code to stop (required)'],
            ['name' => '--cleanup', 'description' => 'Remove the session entry even if the PID is dead'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getUsageExamples(): array
    {
        return [
            'php scripts/backlog-agent.php stop --code=d04',
            'php scripts/backlog-agent.php stop --code=d04 --cleanup',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function handle(array $args, array $options): int
    {
        $code = $this->getSingleOption($options, 'code');
        if ($code === null || $code === '') {
            throw new \RuntimeException('--code=<code> is required.');
        }

        $cleanup = isset($options['cleanup']);

        $session = $this->sessionService->get($code);
        if ($session === null) {
            throw new \RuntimeException(sprintf("No session found for code '%s'.", $code));
        }

        $this->sessionService->updateLastSeen($code);

        $alive = $this->isSessionAlive($session);

        if (!$alive && !$cleanup) {
            throw new \RuntimeException(sprintf(
                "PID %d already dead, run with --cleanup to remove the stale entry.\n" .
                "  php scripts/backlog-agent.php stop --code=%s --cleanup",
                $session->pid,
                $code,
            ));
        }

        if ($alive) {
            $this->terminateClientProcess($session);
        }

        $this->sessionService->remove($code);
        $this->console->ok(sprintf('Session %s removed.', $code));

        return 0;
    }

    /**
     * Sends SIGTERM to the recorded client process group (when set) or to the client PID, waits up to
     * TERMINATION_GRACE_SECONDS, and follows up with SIGKILL if the process is still alive.
     */
    private function terminateClientProcess(AgentSession $session): void
    {
        $target = $this->resolveSignalTarget($session);

        if ($target === null) {
            $this->console->warn(sprintf(
                'Session %s has no recorded client PID or process group; only the wrapper entry will be removed.',
                $session->code,
            ));

            return;
        }

        [$signalTarget, $aliveCheckPid, $label] = $target;
        $this->console->line(sprintf('Sending SIGTERM to %s...', $label));
        $this->signaler->signal($signalTarget, SIGTERM);

        $deadline = time() + $this->terminationGraceSeconds;
        while ($this->signaler->isAlive($aliveCheckPid) && time() < $deadline) {
            usleep(self::POLL_USEC);
        }

        if ($this->signaler->isAlive($aliveCheckPid)) {
            $this->console->warn(sprintf('%s still running after %ds, sending SIGKILL.', $label, $this->terminationGraceSeconds));
            $this->signaler->signal($signalTarget, SIGKILL);
        }
    }

    /**
     * Picks the best signal target for the session: process group first (kills the whole client tree),
     * then client PID, then wrapper PID as last resort. Returns [signal target pid, aliveness check pid, human label]
     * or null when nothing is signalable.
     *
     * @return array{0: int, 1: int, 2: string}|null
     */
    private function resolveSignalTarget(AgentSession $session): ?array
    {
        if ($session->processGroupId !== null && $session->processGroupId > 0) {
            $pid = $session->clientPid ?? $session->processGroupId;

            return [-$session->processGroupId, $pid, sprintf('process group %d (client pid %d)', $session->processGroupId, $pid)];
        }

        if ($session->clientPid !== null && $session->clientPid > 0) {
            return [$session->clientPid, $session->clientPid, sprintf('client PID %d', $session->clientPid)];
        }

        if ($session->pid > 0) {
            return [$session->pid, $session->pid, sprintf('wrapper PID %d', $session->pid)];
        }

        return null;
    }

    /**
     * Returns true when any process tracked by the session is still alive (client first, then wrapper).
     */
    private function isSessionAlive(AgentSession $session): bool
    {
        if ($session->clientPid !== null && $session->clientPid > 0 && $this->signaler->isAlive($session->clientPid)) {
            return true;
        }

        return $session->pid > 0 && $this->signaler->isAlive($session->pid);
    }
}
