<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ChatAuthor;
use App\Repository\ChatMessageRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

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

    #[ORM\Column(type: 'boolean')]
    private bool $isError = false;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

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

    public function getId(): Uuid                      { return $this->id; }
    public function getProject(): Project              { return $this->project; }
    public function getAgent(): Agent                  { return $this->agent; }
    public function getAuthor(): ChatAuthor            { return $this->author; }
    public function getContent(): string               { return $this->content; }
    public function getExchangeId(): string            { return $this->exchangeId; }
    public function isError(): bool                    { return $this->isError; }
    public function getMetadata(): ?array              { return $this->metadata; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
