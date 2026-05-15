<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Command;

use SoManAgent\Script\Backlog\Agent\Client\SessionDriverInterface;
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
    private SessionDriverInterface $sessionDriver;

    /**
     * @param Console $console
     * @param AgentSessionService $sessionService
     * @param SessionDriverInterface $sessionDriver Used to check liveness and terminate the session
     */
    public function __construct(
        Console $console,
        AgentSessionService $sessionService,
        SessionDriverInterface $sessionDriver,
    ) {
        $this->console = $console;
        $this->sessionService = $sessionService;
        $this->sessionDriver = $sessionDriver;
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

        $alive = $this->sessionDriver->isAlive($session);

        if (!$alive && !$cleanup) {
            throw new \RuntimeException(sprintf(
                "Session %s is not alive, run with --cleanup to remove the stale entry.\n" .
                "  php scripts/backlog-agent.php stop --code=%s --cleanup",
                $code,
                $code,
            ));
        }

        if ($alive) {
            $this->sessionDriver->stop($session);
        }

        $this->sessionService->remove($code);
        $this->console->ok(sprintf('Session %s removed.', $code));

        return 0;
    }
}
