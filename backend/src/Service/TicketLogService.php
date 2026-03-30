<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Ticket;
use App\Entity\TicketLog;
use App\Entity\TicketTask;
use App\Repository\TicketLogRepository;
use Doctrine\ORM\EntityManagerInterface;

final class TicketLogService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TicketLogRepository $ticketLogRepository,
    ) {}

    public function log(
        Ticket $ticket,
        string $action,
        ?string $content = null,
        ?TicketTask $ticketTask = null,
        string $kind = 'event',
        ?string $authorType = null,
        ?string $authorName = null,
        bool $requiresAnswer = false,
        ?array $metadata = null,
    ): TicketLog {
        $log = (new TicketLog($ticket, $action, $content, $ticketTask))
            ->setKind($kind)
            ->setAuthorType($authorType)
            ->setAuthorName($authorName)
            ->setRequiresAnswer($requiresAnswer)
            ->setMetadata($metadata);

        $this->em->persist($log);

        return $log;
    }

    public function addComment(
        Ticket $ticket,
        string $content,
        ?TicketTask $ticketTask = null,
        string $authorType = 'user',
        ?string $authorName = null,
        ?string $replyToId = null,
        bool $requiresAnswer = false,
        ?array $metadata = null,
        string $action = 'comment',
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

        $this->em->flush();

        return $log;
    }
}
