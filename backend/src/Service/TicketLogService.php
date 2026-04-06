<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Service;

use App\Entity\Ticket;
use App\Entity\TicketLog;
use App\Entity\TicketTask;
use App\Enum\ClarificationQuestionNecessity;
use App\Repository\TicketLogRepository;

/**
 * Manages ticket log entries: creation of events, replies, and system messages.
 */
final class TicketLogService
{
    /**
     * Initialises the service with its required repository and entity service.
     */
    public function __construct(
        private readonly EntityService       $entityService,
        private readonly TicketLogRepository $ticketLogRepository,
    ) {}

    /**
     * Persists a generic activity log entry on a ticket.
     */
    public function log(
        Ticket      $ticket,
        string      $action,
        ?string     $content     = null,
        ?TicketTask $ticketTask  = null,
        string      $kind        = 'event',
        ?string     $authorType  = null,
        ?string     $authorName  = null,
        bool        $requiresAnswer = false,
        ?array      $metadata    = null,
    ): TicketLog {
        $log = (new TicketLog($ticket, $action, $content, $ticketTask))
            ->setKind($kind)
            ->setAuthorType($authorType)
            ->setAuthorName($authorName)
            ->setRequiresAnswer($requiresAnswer)
            ->setMetadata($metadata);

        $this->entityService->persist($log);

        return $log;
    }

    /**
     * Adds a comment log entry to a ticket, optionally as a reply to an existing log entry.
     */
    public function addComment(
        Ticket      $ticket,
        string      $content,
        ?TicketTask $ticketTask     = null,
        string      $authorType     = 'user',
        ?string     $authorName     = null,
        ?string     $replyToId      = null,
        bool        $requiresAnswer = false,
        ?array      $metadata       = null,
        string      $action         = 'comment',
    ): TicketLog {
        $replyTo = null;
        if ($replyToId !== null) {
            $replyTo = $this->ticketLogRepository->findOneByTicketAndId($ticket, $replyToId);
            if ($replyTo === null) {
                throw new \InvalidArgumentException('Commentaire cible introuvable pour ce ticket.');
            }
        }

        $log = $this->log(
            ticket: $ticket,
            action: $action,
            content: trim($content),
            ticketTask: $ticketTask,
            kind: 'comment',
            authorType: $authorType,
            authorName: $authorName,
            requiresAnswer: $requiresAnswer,
            metadata: $metadata,
        )->setReplyToLogId($replyTo?->getId());

        if ($replyTo !== null && $replyTo->requiresAnswer()) {
            $replyTo->setRequiresAnswer(false);
        }

        $this->entityService->flush();

        return $log;
    }

    /**
     * Edits one user-authored comment or reply while preserving a minimal edit history in metadata.
     */
    public function editComment(Ticket $ticket, string $logId, string $content, ?TicketTask $ticketTask = null): TicketLog
    {
        $log = $this->ticketLogRepository->findOneByTicketAndId($ticket, $logId);
        if ($log === null || $log->getKind() !== 'comment') {
            throw new \InvalidArgumentException('ticket.comment.error.not_found');
        }

        if ($ticketTask !== null && $log->getTicketTask()?->getId()?->toRfc4122() !== $ticketTask->getId()->toRfc4122()) {
            throw new \InvalidArgumentException('ticket.comment.error.not_found');
        }

        if ($log->getAuthorType() !== 'user') {
            throw new \InvalidArgumentException('ticket.comment.error.not_editable');
        }

        $previousContent = trim((string) $log->getContent());
        $nextContent = trim($content);
        if ($previousContent === $nextContent) {
            return $log;
        }

        $log
            ->setContent($nextContent)
            ->setMetadata($this->buildEditedMetadata($log->getMetadata(), $previousContent));

        $this->entityService->flush();

        return $log;
    }

    /**
     * Counts unresolved answer requests across the whole ticket conversation.
     */
    public function countPendingAnswersForTicket(Ticket $ticket): int
    {
        $count = 0;

        foreach ($this->ticketLogRepository->findByTicket($ticket) as $log) {
            if ($log->requiresAnswer()) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Counts unresolved clarification requests that are explicitly marked as blocking.
     */
    public function countPendingBlockingAnswersForTask(TicketTask $task): int
    {
        $count = 0;

        foreach ($this->ticketLogRepository->findByTicketTask($task) as $log) {
            if (!$log->requiresAnswer()) {
                continue;
            }

            if (!ClarificationQuestionNecessity::tryFromMetadata($log->getMetadata())?->isBlocking()) {
                continue;
            }

            ++$count;
        }

        return $count;
    }

    /**
     * Counts unresolved answer requests directly linked to one operational task.
     */
    public function countPendingAnswersForTask(TicketTask $task): int
    {
        $count = 0;

        foreach ($this->ticketLogRepository->findByTicketTask($task) as $log) {
            if ($log->requiresAnswer()) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Returns unresolved answer requests linked to one operational task.
     *
     * @return TicketLog[]
     */
    public function findPendingQuestionsForTask(TicketTask $task): array
    {
        return array_values(array_filter(
            $this->ticketLogRepository->findByTicketTask($task),
            static fn(TicketLog $log): bool => $log->requiresAnswer(),
        ));
    }

    /**
     * Returns updated metadata with an appended edit history entry.
     *
     * @param array<string, mixed>|null $metadata
     * @return array<string, mixed>
     */
    private function buildEditedMetadata(?array $metadata, string $previousContent): array
    {
        $metadata = $metadata ?? [];
        $history = $metadata['editHistory'] ?? [];
        if (!is_array($history)) {
            $history = [];
        }

        $history[] = [
            'editedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'previousContent' => $previousContent,
        ];

        $metadata['editHistory'] = $history;
        $metadata['editCount'] = count($history);
        $metadata['editedAt'] = $history[array_key_last($history)]['editedAt'];

        return $metadata;
    }
}
