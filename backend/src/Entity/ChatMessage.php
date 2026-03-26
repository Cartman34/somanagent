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

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Project    $project,
        Agent      $agent,
        ChatAuthor $author,
        string     $content,
    ) {
        $this->id        = Uuid::v7();
        $this->project   = $project;
        $this->agent     = $agent;
        $this->author    = $author;
        $this->content   = $content;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid                      { return $this->id; }
    public function getProject(): Project              { return $this->project; }
    public function getAgent(): Agent                  { return $this->agent; }
    public function getAuthor(): ChatAuthor            { return $this->author; }
    public function getContent(): string               { return $this->content; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
