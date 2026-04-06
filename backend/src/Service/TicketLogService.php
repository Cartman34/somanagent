<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Service;

use App\Entity\Ticket;
use App\Entity\TicketLog;
use App\Entity\TicketTask;
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
}
