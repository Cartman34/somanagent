<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TicketLogRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

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

    public function __construct(Ticket $ticket, string $action, ?string $content = null, ?TicketTask $ticketTask = null)
    {
        $this->id = Uuid::v7();
        $this->ticket = $ticket;
        $this->action = $action;
        $this->content = $content;
        $this->ticketTask = $ticketTask;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getTicket(): Ticket { return $this->ticket; }
    public function getTicketTask(): ?TicketTask { return $this->ticketTask; }
    public function getAction(): string { return $this->action; }
    public function getContent(): ?string { return $this->content; }
    public function getKind(): string { return $this->kind; }
    public function getAuthorType(): ?string { return $this->authorType; }
    public function getAuthorName(): ?string { return $this->authorName; }
    public function requiresAnswer(): bool { return $this->requiresAnswer; }
    public function getReplyToLogId(): ?Uuid { return $this->replyToLogId; }
    public function getMetadata(): ?array { return $this->metadata; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function setTicketTask(?TicketTask $ticketTask): static { $this->ticketTask = $ticketTask; return $this; }
    public function setKind(string $kind): static { $this->kind = $kind; return $this; }
    public function setAuthorType(?string $authorType): static { $this->authorType = $authorType; return $this; }
    public function setAuthorName(?string $authorName): static { $this->authorName = $authorName; return $this; }
    public function setRequiresAnswer(bool $requiresAnswer): static { $this->requiresAnswer = $requiresAnswer; return $this; }
    public function setReplyToLogId(?Uuid $replyToLogId): static { $this->replyToLogId = $replyToLogId; return $this; }
    public function setMetadata(?array $metadata): static { $this->metadata = $metadata; return $this; }
}
