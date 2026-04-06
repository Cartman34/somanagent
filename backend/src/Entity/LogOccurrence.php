<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LogOccurrenceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Aggregates repeated log events sharing the same fingerprint, tracking first/last seen and occurrence count.
 */
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

    /**
     * Creates an aggregate occurrence for repeated log events with the same fingerprint.
     */
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

    /** Returns the occurrence identifier. */
    public function getId(): Uuid { return $this->id; }
    /** Returns the occurrence category. */
    public function getCategory(): string { return $this->category; }
    /** Returns the occurrence severity level. */
    public function getLevel(): string { return $this->level; }
    /** Returns the deduplication fingerprint. */
    public function getFingerprint(): string { return $this->fingerprint; }
    /** Returns the fallback occurrence title. */
    public function getTitle(): string { return $this->title; }
    /** Returns the translation domain used for the title, if any. */
    public function getTitleDomain(): ?string { return $this->titleDomain; }
    /** Returns the translation key used for the title, if any. */
    public function getTitleKey(): ?string { return $this->titleKey; }
    /** Returns translation parameters used for the title, if any. */
    public function getTitleParameters(): ?array { return $this->titleParameters; }
    /** Returns the fallback occurrence message. */
    public function getMessage(): string { return $this->message; }
    /** Returns the translation domain used for the message, if any. */
    public function getMessageDomain(): ?string { return $this->messageDomain; }
    /** Returns the translation key used for the message, if any. */
    public function getMessageKey(): ?string { return $this->messageKey; }
    /** Returns translation parameters used for the message, if any. */
    public function getMessageParameters(): ?array { return $this->messageParameters; }
    /** Returns the latest event source represented by the occurrence. */
    public function getSource(): string { return $this->source; }
    /** Returns the related project identifier, if any. */
    public function getProjectId(): ?Uuid { return $this->projectId; }
    /** Returns the related task identifier, if any. */
    public function getTaskId(): ?Uuid { return $this->taskId; }
    /** Returns the related agent identifier, if any. */
    public function getAgentId(): ?Uuid { return $this->agentId; }
    /** Returns when the occurrence was first seen. */
    public function getFirstSeenAt(): \DateTimeImmutable { return $this->firstSeenAt; }
    /** Returns when the occurrence was last seen. */
    public function getLastSeenAt(): \DateTimeImmutable { return $this->lastSeenAt; }
    /** Returns how many events were aggregated into this occurrence. */
    public function getOccurrenceCount(): int { return $this->occurrenceCount; }
    /** Returns the current triage status. */
    public function getStatus(): string { return $this->status; }
    /** Returns the latest aggregated log event identifier, if any. */
    public function getLastLogEventId(): ?Uuid { return $this->lastLogEventId; }
    /** Returns a snapshot of the last event context, if any. */
    public function getContextSnapshot(): ?array { return $this->contextSnapshot; }

    /** Stores the related project identifier. */
    public function setProjectId(?Uuid $projectId): static { $this->projectId = $projectId; return $this; }
    /** Stores the related task identifier. */
    public function setTaskId(?Uuid $taskId): static { $this->taskId = $taskId; return $this; }
    /** Stores the related agent identifier. */
    public function setAgentId(?Uuid $agentId): static { $this->agentId = $agentId; return $this; }
    /** Updates the triage status. */
    public function setStatus(string $status): static { $this->status = $status; return $this; }
    /** Stores the latest aggregated log event identifier. */
    public function setLastLogEventId(?Uuid $lastLogEventId): static { $this->lastLogEventId = $lastLogEventId; return $this; }
    /** Stores a snapshot of the latest event context. */
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

    /**
     * Merges a new log event into the existing occurrence aggregate.
     */
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
