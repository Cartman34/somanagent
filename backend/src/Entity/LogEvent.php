<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LogEventRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: LogEventRepository::class)]
#[ORM\Table(name: 'log_event')]
#[ORM\Index(columns: ['occurred_at'], name: 'idx_log_event_occurred_at')]
#[ORM\Index(columns: ['source'], name: 'idx_log_event_source')]
#[ORM\Index(columns: ['category'], name: 'idx_log_event_category')]
#[ORM\Index(columns: ['level'], name: 'idx_log_event_level')]
#[ORM\Index(columns: ['fingerprint'], name: 'idx_log_event_fingerprint')]
#[ORM\Index(columns: ['project_id'], name: 'idx_log_event_project')]
#[ORM\Index(columns: ['task_id'], name: 'idx_log_event_task')]
#[ORM\Index(columns: ['agent_id'], name: 'idx_log_event_agent')]
#[ORM\Index(columns: ['request_ref'], name: 'idx_log_event_request_ref')]
#[ORM\Index(columns: ['exchange_ref'], name: 'idx_log_event_exchange_ref')]
#[ORM\Index(columns: ['trace_ref'], name: 'idx_log_event_trace_ref')]
class LogEvent
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 20)]
    private string $source;

    #[ORM\Column(length: 20)]
    private string $category;

    #[ORM\Column(length: 20)]
    private string $level;

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

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $fingerprint = null;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $projectId = null;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $taskId = null;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $agentId = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $exchangeRef = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $requestRef = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $traceRef = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $context = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $stack = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $origin = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $rawPayload = null;

    #[ORM\Column(name: 'occurred_at')]
    private \DateTimeImmutable $occurredAt;

    public function __construct(string $source, string $category, string $level, string $title, string $message)
    {
        $this->id = Uuid::v7();
        $this->source = $source;
        $this->category = $category;
        $this->level = $level;
        $this->title = $title;
        $this->message = $message;
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getSource(): string { return $this->source; }
    public function getCategory(): string { return $this->category; }
    public function getLevel(): string { return $this->level; }
    public function getTitle(): string { return $this->title; }
    public function getTitleDomain(): ?string { return $this->titleDomain; }
    public function getTitleKey(): ?string { return $this->titleKey; }
    public function getTitleParameters(): ?array { return $this->titleParameters; }
    public function getMessage(): string { return $this->message; }
    public function getMessageDomain(): ?string { return $this->messageDomain; }
    public function getMessageKey(): ?string { return $this->messageKey; }
    public function getMessageParameters(): ?array { return $this->messageParameters; }
    public function getFingerprint(): ?string { return $this->fingerprint; }
    public function getProjectId(): ?Uuid { return $this->projectId; }
    public function getTaskId(): ?Uuid { return $this->taskId; }
    public function getAgentId(): ?Uuid { return $this->agentId; }
    public function getExchangeRef(): ?string { return $this->exchangeRef; }
    public function getRequestRef(): ?string { return $this->requestRef; }
    public function getTraceRef(): ?string { return $this->traceRef; }
    public function getContext(): ?array { return $this->context; }
    public function getStack(): ?string { return $this->stack; }
    public function getOrigin(): ?string { return $this->origin; }
    public function getRawPayload(): ?array { return $this->rawPayload; }
    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }

    public function setFingerprint(?string $fingerprint): static { $this->fingerprint = $fingerprint; return $this; }
    public function setProjectId(?Uuid $projectId): static { $this->projectId = $projectId; return $this; }
    public function setTaskId(?Uuid $taskId): static { $this->taskId = $taskId; return $this; }
    public function setAgentId(?Uuid $agentId): static { $this->agentId = $agentId; return $this; }
    public function setExchangeRef(?string $exchangeRef): static { $this->exchangeRef = $exchangeRef; return $this; }
    public function setRequestRef(?string $requestRef): static { $this->requestRef = $requestRef; return $this; }
    public function setTraceRef(?string $traceRef): static { $this->traceRef = $traceRef; return $this; }
    public function setContext(?array $context): static { $this->context = $context; return $this; }
    public function setStack(?string $stack): static { $this->stack = $stack; return $this; }
    public function setOrigin(?string $origin): static { $this->origin = $origin; return $this; }
    public function setRawPayload(?array $rawPayload): static { $this->rawPayload = $rawPayload; return $this; }

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
}
