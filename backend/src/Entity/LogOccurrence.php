<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LogOccurrenceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: LogOccurrenceRepository::class)]
#[ORM\Table(name: 'log_occurrence')]
#[ORM\UniqueConstraint(name: 'uniq_log_occurrence_category_level_fingerprint', columns: ['category', 'level', 'fingerprint'])]
#[ORM\Index(columns: ['source'], name: 'idx_log_occurrence_source')]
#[ORM\Index(columns: ['status'], name: 'idx_log_occurrence_status')]
#[ORM\Index(columns: ['project_id'], name: 'idx_log_occurrence_project')]
#[ORM\Index(columns: ['task_id'], name: 'idx_log_occurrence_task')]
#[ORM\Index(columns: ['agent_id'], name: 'idx_log_occurrence_agent')]
class LogOccurrence
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 20)]
    private string $category;

    #[ORM\Column(length: 20)]
    private string $level;

    #[ORM\Column(length: 64)]
    private string $fingerprint;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $titleDomain = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $titleKey = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $titleParameters = null;

    #[ORM\Column(type: 'text')]
    private string $message;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $messageDomain = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $messageKey = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $messageParameters = null;

    #[ORM\Column(length: 20)]
    private string $source;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $projectId = null;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $taskId = null;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $agentId = null;

    #[ORM\Column(name: 'first_seen_at')]
    private \DateTimeImmutable $firstSeenAt;

    #[ORM\Column(name: 'last_seen_at')]
    private \DateTimeImmutable $lastSeenAt;

    #[ORM\Column]
    private int $occurrenceCount = 1;

    #[ORM\Column(length: 20)]
    private string $status = 'open';

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $lastLogEventId = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $contextSnapshot = null;

    public function __construct(string $category, string $level, string $fingerprint, string $title, string $message, string $source)
    {
        $this->id = Uuid::v7();
        $this->category = $category;
        $this->level = $level;
        $this->fingerprint = $fingerprint;
        $this->title = $title;
        $this->message = $message;
        $this->source = $source;
        $this->firstSeenAt = new \DateTimeImmutable();
        $this->lastSeenAt = $this->firstSeenAt;
    }

    public function getId(): Uuid { return $this->id; }
    public function getCategory(): string { return $this->category; }
    public function getLevel(): string { return $this->level; }
    public function getFingerprint(): string { return $this->fingerprint; }
    public function getTitle(): string { return $this->title; }
    public function getTitleDomain(): ?string { return $this->titleDomain; }
    public function getTitleKey(): ?string { return $this->titleKey; }
    public function getTitleParameters(): ?array { return $this->titleParameters; }
    public function getMessage(): string { return $this->message; }
    public function getMessageDomain(): ?string { return $this->messageDomain; }
    public function getMessageKey(): ?string { return $this->messageKey; }
    public function getMessageParameters(): ?array { return $this->messageParameters; }
    public function getSource(): string { return $this->source; }
    public function getProjectId(): ?Uuid { return $this->projectId; }
    public function getTaskId(): ?Uuid { return $this->taskId; }
    public function getAgentId(): ?Uuid { return $this->agentId; }
    public function getFirstSeenAt(): \DateTimeImmutable { return $this->firstSeenAt; }
    public function getLastSeenAt(): \DateTimeImmutable { return $this->lastSeenAt; }
    public function getOccurrenceCount(): int { return $this->occurrenceCount; }
    public function getStatus(): string { return $this->status; }
    public function getLastLogEventId(): ?Uuid { return $this->lastLogEventId; }
    public function getContextSnapshot(): ?array { return $this->contextSnapshot; }

    public function setProjectId(?Uuid $projectId): static { $this->projectId = $projectId; return $this; }
    public function setTaskId(?Uuid $taskId): static { $this->taskId = $taskId; return $this; }
    public function setAgentId(?Uuid $agentId): static { $this->agentId = $agentId; return $this; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }
    public function setLastLogEventId(?Uuid $lastLogEventId): static { $this->lastLogEventId = $lastLogEventId; return $this; }
    public function setContextSnapshot(?array $contextSnapshot): static { $this->contextSnapshot = $contextSnapshot; return $this; }

    /**
     * @param array<string, scalar|null>|null $parameters
     */
    public function setTitleTranslation(?string $domain, ?string $key, ?array $parameters = null): static
    {
        $this->titleDomain = $domain;
        $this->titleKey = $key;
        $this->titleParameters = $parameters;

        return $this;
    }

    /**
     * @param array<string, scalar|null>|null $parameters
     */
    public function setMessageTranslation(?string $domain, ?string $key, ?array $parameters = null): static
    {
        $this->messageDomain = $domain;
        $this->messageKey = $key;
        $this->messageParameters = $parameters;

        return $this;
    }

    public function registerOccurrence(LogEvent $event): static
    {
        $this->lastSeenAt = $event->getOccurredAt();
        $this->occurrenceCount++;
        $this->lastLogEventId = $event->getId();
        $this->title = $event->getTitle();
        $this->titleDomain = $event->getTitleDomain();
        $this->titleKey = $event->getTitleKey();
        $this->titleParameters = $event->getTitleParameters();
        $this->message = $event->getMessage();
        $this->messageDomain = $event->getMessageDomain();
        $this->messageKey = $event->getMessageKey();
        $this->messageParameters = $event->getMessageParameters();
        $this->source = $event->getSource();
        $this->contextSnapshot = $event->getContext();

        if ($event->getProjectId() !== null) {
            $this->projectId = $event->getProjectId();
        }
        if ($event->getTaskId() !== null) {
            $this->taskId = $event->getTaskId();
        }
        if ($event->getAgentId() !== null) {
            $this->agentId = $event->getAgentId();
        }

        return $this;
    }
}
