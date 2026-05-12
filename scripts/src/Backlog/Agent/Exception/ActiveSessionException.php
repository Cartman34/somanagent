<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Exception;

use SoManAgent\Script\Backlog\Agent\Model\AgentSession;

/**
 * Thrown when a start command targets a code that already has an active session.
 */
final class ActiveSessionException extends \RuntimeException
{
    /**
     * @param AgentSession $session Active session for the requested code
     * @param string $projectRoot Absolute path to the main workspace (used to shorten worktree path)
     * @param string|null $current Human-readable label of the current backlog task, if any
     */
    public function __construct(AgentSession $session, string $projectRoot, ?string $current = null)
    {
        $relativeWorktree = str_replace($projectRoot . '/', '', $session->worktree);
        $pidStatus = $session->isAlive() ? 'running' : 'dead';

        $message = sprintf(
            "Code %s already has an active session:\n" .
            "  client     : %s\n" .
            "  role       : %s\n" .
            "  pid        : %d (%s)\n" .
            "  worktree   : %s\n" .
            "  started_at : %s\n" .
            "  last_seen  : %s\n" .
            "  current    : %s\n\n" .
            "Options:\n",
            $session->code,
            $session->client->value,
            $session->role->value,
            $session->pid,
            $pidStatus,
            $relativeWorktree,
            $session->startedAt->format(\DateTimeInterface::ATOM),
            $session->lastSeenAt->format(\DateTimeInterface::ATOM),
            $current ?? '—',
        );

        if ($session->isAlive()) {
            $message .= sprintf(
                "  - Stop the session       : php scripts/backlog-agent.php stop --code=%s\n" .
                "  - Use a different code   : drop --code or pass an unused one\n" .
                "  - Cleanup if PID is dead : php scripts/backlog-agent.php stop --code=%s --cleanup",
                $session->code,
                $session->code,
            );
        } else {
            $message .= sprintf(
                "  - Cleanup stale entry    : php scripts/backlog-agent.php stop --code=%s --cleanup\n" .
                "  - Stop a live session    : php scripts/backlog-agent.php stop --code=%s\n" .
                "  - Use a different code   : drop --code or pass an unused one",
                $session->code,
                $session->code,
            );
        }

        parent::__construct($message);
    }
}
