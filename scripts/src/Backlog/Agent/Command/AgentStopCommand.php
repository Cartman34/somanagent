<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Command;

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
    private Console $console;
    private AgentSessionService $sessionService;

    /**
     * @param Console $console
     * @param AgentSessionService $sessionService
     */
    public function __construct(Console $console, AgentSessionService $sessionService)
    {
        $this->console = $console;
        $this->sessionService = $sessionService;
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

        $alive = $session->isAlive();

        if (!$alive && !$cleanup) {
            throw new \RuntimeException(sprintf(
                "PID %d already dead, run with --cleanup to remove the stale entry.\n" .
                "  php scripts/backlog-agent.php stop --code=%s --cleanup",
                $session->pid,
                $code,
            ));
        }

        if ($alive) {
            $this->console->line(sprintf('Sending SIGTERM to PID %d...', $session->pid));
            posix_kill($session->pid, SIGTERM);

            $deadline = time() + 5;
            while ($session->isAlive() && time() < $deadline) {
                usleep(200_000);
            }

            if ($session->isAlive()) {
                $this->console->warn(sprintf('Process %d still running after 5s, sending SIGKILL.', $session->pid));
                posix_kill($session->pid, SIGKILL);
            }
        }

        $this->sessionService->remove($code);
        $this->console->ok(sprintf('Session %s removed.', $code));

        return 0;
    }
}
