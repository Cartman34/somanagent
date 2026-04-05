<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Service;

use App\Entity\AgentTaskExecution;
use App\Entity\Project;
use App\Entity\Ticket;
use App\Entity\TicketLog;
use App\Entity\TicketTask;
use App\Port\RealtimePublisherPort;
use App\ValueObject\RealtimeUpdate;
use Psr\Log\LoggerInterface;

/**
 * High-level application service publishing normalized realtime events.
 */
final class RealtimeUpdateService
{
    /**
     * Initializes the service with the realtime publisher port and canonical topic factory.
     */
    public function __construct(
        private readonly RealtimePublisherPort $publisher,
        private readonly RealtimeTopicFactory $topics,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Publishes a project-level change.
     *
     * @param array<string, mixed> $payload
     */
    public function publishProjectChanged(Project $project, string $reason, array $payload = []): void
    {
        $projectId = (string) $project->getId();

        $this->safePublish(RealtimeUpdate::create(
            topics: [
                $this->topics->project($projectId),
                $this->topics->projectTickets($projectId),
                $this->topics->projectAudit($projectId),
                $this->topics->projectTokens($projectId),
            ],
            type: 'project.changed',
            payload: array_merge([
                'projectId' => $projectId,
                'reason' => $reason,
            ], $payload),
        ));
    }

    /**
     * Publishes a ticket-level change.
     *
     * @param array<string, mixed> $payload
     */
    public function publishTicketChanged(Ticket $ticket, string $reason, array $payload = []): void
    {
        $projectId = (string) $ticket->getProject()->getId();
        $ticketId = (string) $ticket->getId();

        $this->safePublish(RealtimeUpdate::create(
            topics: [
                $this->topics->project($projectId),
                $this->topics->projectTickets($projectId),
                $this->topics->ticket($projectId, $ticketId),
                $this->topics->projectAudit($projectId),
            ],
            type: 'ticket.changed',
            payload: array_merge([
                'projectId' => $projectId,
                'ticketId' => $ticketId,
                'reason' => $reason,
            ], $payload),
        ));
    }

    /**
     * Publishes a ticket deletion for all subscribers interested in this project aggregate.
     */
    public function publishTicketDeleted(Project $project, string $ticketId): void
    {
        $projectId = (string) $project->getId();

        $this->safePublish(RealtimeUpdate::create(
            topics: [
                $this->topics->project($projectId),
                $this->topics->projectTickets($projectId),
                $this->topics->ticket($projectId, $ticketId),
                $this->topics->projectAudit($projectId),
            ],
            type: 'ticket.deleted',
            payload: [
                'projectId' => $projectId,
                'ticketId' => $ticketId,
            ],
        ));
    }

    /**
     * Publishes a task-level change.
     *
     * @param array<string, mixed> $payload
     */
    public function publishTaskChanged(TicketTask $task, string $reason, array $payload = []): void
    {
        $projectId = (string) $task->getTicket()->getProject()->getId();
        $ticketId = (string) $task->getTicket()->getId();
        $taskId = (string) $task->getId();

        $this->safePublish(RealtimeUpdate::create(
            topics: [
                $this->topics->project($projectId),
                $this->topics->projectTickets($projectId),
                $this->topics->ticket($projectId, $ticketId),
                $this->topics->task($projectId, $taskId),
                $this->topics->projectAudit($projectId),
            ],
            type: 'task.changed',
            payload: array_merge([
                'projectId' => $projectId,
                'ticketId' => $ticketId,
                'taskId' => $taskId,
                'reason' => $reason,
            ], $payload),
        ));
    }

    /**
     * Publishes a task deletion for all subscribers interested in this project aggregate.
     */
    public function publishTaskDeleted(Project $project, string $ticketId, string $taskId): void
    {
        $projectId = (string) $project->getId();

        $this->safePublish(RealtimeUpdate::create(
            topics: [
                $this->topics->project($projectId),
                $this->topics->projectTickets($projectId),
                $this->topics->ticket($projectId, $ticketId),
                $this->topics->task($projectId, $taskId),
                $this->topics->projectAudit($projectId),
            ],
            type: 'task.deleted',
            payload: [
                'projectId' => $projectId,
                'ticketId' => $ticketId,
                'taskId' => $taskId,
            ],
        ));
    }

    /**
     * Publishes a ticket log mutation relevant to one ticket and optionally one task.
     */
    public function publishTicketLogChanged(TicketLog $log): void
    {
        $ticket = $log->getTicket();
        $projectId = (string) $ticket->getProject()->getId();
        $ticketId = (string) $ticket->getId();
        $taskId = $log->getTicketTask()?->getId()?->toRfc4122();

        $topics = [
            $this->topics->project($projectId),
            $this->topics->projectTickets($projectId),
            $this->topics->ticket($projectId, $ticketId),
        ];

        if ($taskId !== null) {
            $topics[] = $this->topics->task($projectId, $taskId);
        }

        $this->safePublish(RealtimeUpdate::create(
            topics: $topics,
            type: 'ticket.log.changed',
            payload: [
                'projectId' => $projectId,
                'ticketId' => $ticketId,
                'taskId' => $taskId,
                'logId' => (string) $log->getId(),
                'action' => $log->getAction(),
                'kind' => $log->getKind(),
                'requiresAnswer' => $log->requiresAnswer(),
            ],
        ));
    }

    /**
     * Publishes an execution-level change.
     *
     * @param list<string> $taskIds
     */
    public function publishExecutionChanged(TicketTask $task, AgentTaskExecution $execution, string $reason, array $taskIds = []): void
    {
        $projectId = (string) $task->getTicket()->getProject()->getId();
        $ticketId = (string) $task->getTicket()->getId();
        $taskId = (string) $task->getId();

        $topics = [
            $this->topics->project($projectId),
            $this->topics->projectTickets($projectId),
            $this->topics->ticket($projectId, $ticketId),
            $this->topics->task($projectId, $taskId),
            $this->topics->projectTokens($projectId),
        ];

        foreach ($taskIds as $linkedTaskId) {
            $topics[] = $this->topics->task($projectId, $linkedTaskId);
        }

        $this->safePublish(RealtimeUpdate::create(
            topics: $topics,
            type: 'execution.changed',
            payload: [
                'projectId' => $projectId,
                'ticketId' => $ticketId,
                'taskId' => $taskId,
                'executionId' => (string) $execution->getId(),
                'status' => $execution->getStatus()->value,
                'reason' => $reason,
                'taskIds' => $taskIds,
            ],
        ));
    }

    /**
     * Publishes one realtime update without blocking business mutations when the hub is unavailable.
     */
    private function safePublish(RealtimeUpdate $update): void
    {
        try {
            $this->publisher->publish($update);
        } catch (\Throwable $exception) {
            $this->logger->warning('Realtime update publication failed.', [
                'event_type' => $update->getType(),
                'topics' => $update->getTopics(),
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
