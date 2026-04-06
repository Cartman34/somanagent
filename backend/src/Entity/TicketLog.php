<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TicketLogRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Represents an audit log entry for a ticket, recording actions, events, and optional threaded replies.
 */
#[ORM\Entity(repositoryClass: TicketLogRepository::class)]
#[ORM\Table(name: 'ticket_log')]
class TicketLog
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Ticket::class, inversedBy: 'logs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Ticket $ticket;

    #[ORM\ManyToOne(targetEntity: TicketTask::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?TicketTask $ticketTask = null;

    #[ORM\Column(length: 100)]
    private string $action;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $content = null;

    #[ORM\Column(length: 20, options: ['default' => 'event'])]
    private string $kind = 'event';

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $authorType = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $authorName = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $requiresAnswer = false;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $replyToLogId = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * Creates a ticket log entry for a ticket and optional task context.
     */
    public function __construct(Ticket $ticket, string $action, ?string $content = null, ?TicketTask $ticketTask = null)
    {
        $this->id = Uuid::v7();
        $this->ticket = $ticket;
        $this->action = $action;
        $this->content = $content;
        $this->ticketTask = $ticketTask;
        $this->createdAt = new \DateTimeImmutable();
    }

    /** Returns the log entry identifier. */
    public function getId(): Uuid { return $this->id; }
    /** Returns the parent ticket. */
    public function getTicket(): Ticket { return $this->ticket; }
    /** Returns the related ticket task, if any. */
    public function getTicketTask(): ?TicketTask { return $this->ticketTask; }
    /** Returns the action key associated with the log entry. */
    public function getAction(): string { return $this->action; }
    /** Returns the optional log content. */
    public function getContent(): ?string { return $this->content; }
    /** Returns the kind of log entry. */
    public function getKind(): string { return $this->kind; }
    /** Returns the author type, if any. */
    public function getAuthorType(): ?string { return $this->authorType; }
    /** Returns the author display name, if any. */
    public function getAuthorName(): ?string { return $this->authorName; }
    /** Indicates whether the log entry expects a reply. */
    public function requiresAnswer(): bool { return $this->requiresAnswer; }
    /** Returns the referenced parent log entry identifier, if any. */
    public function getReplyToLogId(): ?Uuid { return $this->replyToLogId; }
    /** Returns structured metadata attached to the log entry, if any. */
    public function getMetadata(): ?array { return $this->metadata; }
    /** Returns when the log entry was created. */
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** Assigns or clears the related ticket task. */
    public function setTicketTask(?TicketTask $ticketTask): static { $this->ticketTask = $ticketTask; return $this; }
    /** Updates the log entry kind. */
    public function setKind(string $kind): static { $this->kind = $kind; return $this; }
    /** Updates the author type. */
    public function setAuthorType(?string $authorType): static { $this->authorType = $authorType; return $this; }
    /** Updates the author display name. */
    public function setAuthorName(?string $authorName): static { $this->authorName = $authorName; return $this; }
    /** Flags whether the log entry requires an answer. */
    public function setRequiresAnswer(bool $requiresAnswer): static { $this->requiresAnswer = $requiresAnswer; return $this; }
    /** Stores the replied log entry identifier. */
    public function setReplyToLogId(?Uuid $replyToLogId): static { $this->replyToLogId = $replyToLogId; return $this; }
    /** Stores structured metadata for the log entry. */
    public function setMetadata(?array $metadata): static { $this->metadata = $metadata; return $this; }
}
