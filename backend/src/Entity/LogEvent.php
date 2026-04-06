<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LogEventRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Represents a single structured log event with source, category, level, and contextual metadata.
 */
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

    /**
     * Creates a structured log event with its core source, category, level, title, and message.
     */
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

    /** Returns the log event identifier. */
    public function getId(): Uuid { return $this->id; }
    /** Returns the event source. */
    public function getSource(): string { return $this->source; }
    /** Returns the event category. */
    public function getCategory(): string { return $this->category; }
    /** Returns the event severity level. */
    public function getLevel(): string { return $this->level; }
    /** Returns the fallback event title. */
    public function getTitle(): string { return $this->title; }
    /** Returns the translation domain used for the title, if any. */
    public function getTitleDomain(): ?string { return $this->titleDomain; }
    /** Returns the translation key used for the title, if any. */
    public function getTitleKey(): ?string { return $this->titleKey; }
    /** Returns translation parameters used for the title, if any. */
    public function getTitleParameters(): ?array { return $this->titleParameters; }
    /** Returns the fallback event message. */
    public function getMessage(): string { return $this->message; }
    /** Returns the translation domain used for the message, if any. */
    public function getMessageDomain(): ?string { return $this->messageDomain; }
    /** Returns the translation key used for the message, if any. */
    public function getMessageKey(): ?string { return $this->messageKey; }
    /** Returns translation parameters used for the message, if any. */
    public function getMessageParameters(): ?array { return $this->messageParameters; }
    /** Returns the deduplication fingerprint, if any. */
    public function getFingerprint(): ?string { return $this->fingerprint; }
    /** Returns the related project identifier, if any. */
    public function getProjectId(): ?Uuid { return $this->projectId; }
    /** Returns the related task identifier, if any. */
    public function getTaskId(): ?Uuid { return $this->taskId; }
    /** Returns the related agent identifier, if any. */
    public function getAgentId(): ?Uuid { return $this->agentId; }
    /** Returns the related exchange reference, if any. */
    public function getExchangeRef(): ?string { return $this->exchangeRef; }
    /** Returns the request correlation reference, if any. */
    public function getRequestRef(): ?string { return $this->requestRef; }
    /** Returns the trace reference, if any. */
    public function getTraceRef(): ?string { return $this->traceRef; }
    /** Returns structured context attached to the event, if any. */
    public function getContext(): ?array { return $this->context; }
    /** Returns the stack trace attached to the event, if any. */
    public function getStack(): ?string { return $this->stack; }
    /** Returns the origin of the event, if any. */
    public function getOrigin(): ?string { return $this->origin; }
    /** Returns the raw provider payload, if any. */
    public function getRawPayload(): ?array { return $this->rawPayload; }
    /** Returns when the event occurred. */
    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }

    /** Stores the deduplication fingerprint. */
    public function setFingerprint(?string $fingerprint): static { $this->fingerprint = $fingerprint; return $this; }
    /** Stores the related project identifier. */
    public function setProjectId(?Uuid $projectId): static { $this->projectId = $projectId; return $this; }
    /** Stores the related task identifier. */
    public function setTaskId(?Uuid $taskId): static { $this->taskId = $taskId; return $this; }
    /** Stores the related agent identifier. */
    public function setAgentId(?Uuid $agentId): static { $this->agentId = $agentId; return $this; }
    /** Stores the related exchange reference. */
    public function setExchangeRef(?string $exchangeRef): static { $this->exchangeRef = $exchangeRef; return $this; }
    /** Stores the request correlation reference. */
    public function setRequestRef(?string $requestRef): static { $this->requestRef = $requestRef; return $this; }
    /** Stores the trace reference. */
    public function setTraceRef(?string $traceRef): static { $this->traceRef = $traceRef; return $this; }
    /** Stores structured context for the event. */
    public function setContext(?array $context): static { $this->context = $context; return $this; }
    /** Stores the stack trace for the event. */
    public function setStack(?string $stack): static { $this->stack = $stack; return $this; }
    /** Stores the event origin label. */
    public function setOrigin(?string $origin): static { $this->origin = $origin; return $this; }
    /** Stores the raw provider payload. */
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
