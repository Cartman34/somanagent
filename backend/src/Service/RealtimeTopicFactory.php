<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Service;

/**
 * Builds canonical Mercure topics used across the application.
 */
final class RealtimeTopicFactory
{
    public const BASE_URI = 'https://somanagent.local/realtime';

    /**
     * Returns the project root topic.
     */
    public function project(string $projectId): string
    {
        return sprintf('%s/projects/%s', self::BASE_URI, $projectId);
    }

    /**
     * Returns the project ticket collection topic.
     */
    public function projectTickets(string $projectId): string
    {
        return sprintf('%s/tickets', $this->project($projectId));
    }

    /**
     * Returns the topic for one ticket aggregate.
     */
    public function ticket(string $projectId, string $ticketId): string
    {
        return sprintf('%s/%s', $this->projectTickets($projectId), $ticketId);
    }

    /**
     * Returns the topic for one operational task.
     */
    public function task(string $projectId, string $taskId): string
    {
        return sprintf('%s/tasks/%s', $this->project($projectId), $taskId);
    }

    /**
     * Returns the project audit topic.
     */
    public function projectAudit(string $projectId): string
    {
        return sprintf('%s/audit', $this->project($projectId));
    }

    /**
     * Returns the project token usage topic.
     */
    public function projectTokens(string $projectId): string
    {
        return sprintf('%s/tokens', $this->project($projectId));
    }
}
