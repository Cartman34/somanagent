<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Agent\Command;

use Sowapps\SoManAgent\Script\Console;
use Sowapps\SoManAgent\Script\Backlog\Agent\Service\AgentSessionService;
use Sowapps\SoManAgent\Script\Backlog\Agent\Client\SessionDriverInterface;
use Sowapps\SoManAgent\Script\Backlog\Enum\BacklogCliOption;

/**
 * Stops a live agent session, cleans up a stale entry, or kills an orphan driver session.
 *
 * Usage:
 *   php scripts/backlog-agent.php stop --code=<code> [--cleanup]
 *
 * When no registry entry exists for <code> but the session driver still reports a live session
 * (e.g. an orphan tmux session left after the registry was already pruned), the driver session is
 * killed directly and the command exits 0 with a dedicated message. This makes `stop` the correct
 * remediation for the "A live driver session already exists" error from `start`.
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
    public function handle(array $args, array $options): int
    {
        $code = $this->getSingleOption($options, 'code');
        if ($code === null || $code === '') {
            throw new \RuntimeException('--code=<code> is required.');
        }

        $cleanup = isset($options[BacklogCliOption::CLEANUP->value]);

        $session = $this->sessionService->get($code);
        if ($session === null) {
            if ($this->sessionDriver->sessionExists($code)) {
                $this->sessionDriver->kill($code);
                $this->console->ok(sprintf("Killed orphan driver session for code '%s' (no registry entry).", $code));

                return 0;
            }

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
