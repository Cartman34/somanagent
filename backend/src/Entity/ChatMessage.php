<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ChatAuthor;
use App\Repository\ChatMessageRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Represents a single message in a conversation between a user and an agent within a project.
 */
#[ORM\Entity(repositoryClass: ChatMessageRepository::class)]
#[ORM\Table(name: 'chat_message')]
class ChatMessage
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Project $project;

    #[ORM\ManyToOne(targetEntity: Agent::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Agent $agent;

    #[ORM\Column(enumType: ChatAuthor::class)]
    private ChatAuthor $author;

    #[ORM\Column(type: 'text')]
    private string $content;

    #[ORM\Column(length: 36)]
    private string $exchangeId;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $replyToMessageId = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isError = false;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * Creates a chat message linked to a project, agent, and exchange thread.
     */
    public function __construct(
        Project    $project,
        Agent      $agent,
        ChatAuthor $author,
        string     $content,
        ?string    $exchangeId = null,
        bool       $isError = false,
        ?array     $metadata = null,
    ) {
        $this->id        = Uuid::v7();
        $this->project   = $project;
        $this->agent     = $agent;
        $this->author    = $author;
        $this->content   = $content;
        $this->exchangeId = $exchangeId ?? (string) Uuid::v7();
        $this->isError   = $isError;
        $this->metadata  = $metadata;
        $this->createdAt = new \DateTimeImmutable();
    }

    /** Returns the message identifier. */
    public function getId(): Uuid                      { return $this->id; }
    /** Returns the project that owns the conversation. */
    public function getProject(): Project              { return $this->project; }
    /** Returns the agent involved in the conversation. */
    public function getAgent(): Agent                  { return $this->agent; }
    /** Returns the author kind of the message. */
    public function getAuthor(): ChatAuthor            { return $this->author; }
    /** Returns the message body. */
    public function getContent(): string               { return $this->content; }
    /** Returns the exchange identifier grouping related messages. */
    public function getExchangeId(): string            { return $this->exchangeId; }
    /** Returns the replied message identifier, if any. */
    public function getReplyToMessageId(): ?Uuid       { return $this->replyToMessageId; }
    /** Updates the message body. */
    public function setContent(string $content): static { $this->content = $content; return $this; }
    /** Links the message to the replied message identifier. */
    public function setReplyToMessageId(?Uuid $replyToMessageId): static { $this->replyToMessageId = $replyToMessageId; return $this; }
    /** Indicates whether the message represents an error response. */
    public function isError(): bool                    { return $this->isError; }
    /** Returns optional structured metadata attached to the message. */
    public function getMetadata(): ?array              { return $this->metadata; }
    /** Replaces structured metadata attached to the message. */
    public function setMetadata(?array $metadata): static { $this->metadata = $metadata; return $this; }
    /** Returns when the message was created. */
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
