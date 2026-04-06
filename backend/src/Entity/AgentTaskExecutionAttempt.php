<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TaskExecutionAttemptStatus;
use App\Repository\AgentTaskExecutionAttemptRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Records a single attempt within an AgentTaskExecution, capturing agent, status, and error details.
 */
#[ORM\Entity(repositoryClass: AgentTaskExecutionAttemptRepository::class)]
#[ORM\Table(name: 'agent_task_execution_attempt')]
#[ORM\UniqueConstraint(name: 'uniq_agent_task_execution_attempt_execution_number', columns: ['execution_id', 'attempt_number'])]
#[ORM\Index(columns: ['execution_id'], name: 'idx_agent_task_execution_attempt_execution')]
class AgentTaskExecutionAttempt
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: AgentTaskExecution::class, inversedBy: 'attempts')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private AgentTaskExecution $execution;

    #[ORM\Column(type: 'smallint')]
    private int $attemptNumber;

    #[ORM\ManyToOne(targetEntity: Agent::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Agent $agent = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $messengerReceiver = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $requestRef = null;

    #[ORM\Column(enumType: TaskExecutionAttemptStatus::class)]
    private TaskExecutionAttemptStatus $status = TaskExecutionAttemptStatus::Running;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $willRetry = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $errorScope = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * Creates an execution attempt and attaches it to the parent execution.
     */
    public function __construct(AgentTaskExecution $execution, int $attemptNumber)
    {
        $this->id = Uuid::v7();
        $this->execution = $execution;
        $this->attemptNumber = max(1, $attemptNumber);
        $this->createdAt = new \DateTimeImmutable();
        $execution->addAttempt($this);
    }

    /** Returns the attempt identifier. */
    public function getId(): Uuid { return $this->id; }
    /** Returns the parent execution of this attempt. */
    public function getExecution(): AgentTaskExecution { return $this->execution; }
    /** Returns the sequential attempt number. */
    public function getAttemptNumber(): int { return $this->attemptNumber; }
    /** Returns the agent that handled this attempt, if known. */
    public function getAgent(): ?Agent { return $this->agent; }
    /** Returns the Messenger receiver used for this attempt, if any. */
    public function getMessengerReceiver(): ?string { return $this->messengerReceiver; }
    /** Returns the request correlation reference, if any. */
    public function getRequestRef(): ?string { return $this->requestRef; }
    /** Returns the execution status for this attempt. */
    public function getStatus(): TaskExecutionAttemptStatus { return $this->status; }
    /** Indicates whether the execution should be retried after this attempt. */
    public function willRetry(): bool { return $this->willRetry; }
    /** Returns when the attempt started. */
    public function getStartedAt(): ?\DateTimeImmutable { return $this->startedAt; }
    /** Returns when the attempt finished. */
    public function getFinishedAt(): ?\DateTimeImmutable { return $this->finishedAt; }
    /** Returns the last error message captured for this attempt. */
    public function getErrorMessage(): ?string { return $this->errorMessage; }
    /** Returns the scope associated with the last error. */
    public function getErrorScope(): ?string { return $this->errorScope; }
    /** Returns when the attempt record was created. */
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** Assigns the effective agent that handled this attempt. */
    public function setAgent(?Agent $agent): static { $this->agent = $agent; return $this; }
    /** Stores the Messenger receiver used for this attempt. */
    public function setMessengerReceiver(?string $messengerReceiver): static { $this->messengerReceiver = $messengerReceiver; return $this; }
    /** Stores the request correlation reference for this attempt. */
    public function setRequestRef(?string $requestRef): static { $this->requestRef = $requestRef; return $this; }
    /** Updates the execution status of this attempt. */
    public function setStatus(TaskExecutionAttemptStatus $status): static { $this->status = $status; return $this; }
    /** Flags whether the execution should be retried after this attempt. */
    public function setWillRetry(bool $willRetry): static { $this->willRetry = $willRetry; return $this; }
    /** Stores when the attempt started. */
    public function setStartedAt(?\DateTimeImmutable $startedAt): static { $this->startedAt = $startedAt; return $this; }
    /** Stores when the attempt finished. */
    public function setFinishedAt(?\DateTimeImmutable $finishedAt): static { $this->finishedAt = $finishedAt; return $this; }
    /** Stores the last error message for this attempt. */
    public function setErrorMessage(?string $errorMessage): static { $this->errorMessage = $errorMessage; return $this; }
    /** Stores the scope associated with the last error. */
    public function setErrorScope(?string $errorScope): static { $this->errorScope = $errorScope; return $this; }
}
