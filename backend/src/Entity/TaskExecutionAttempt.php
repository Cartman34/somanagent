<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TaskExecutionAttemptStatus;
use App\Repository\TaskExecutionAttemptRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TaskExecutionAttemptRepository::class)]
#[ORM\Table(name: 'task_execution_attempt')]
#[ORM\UniqueConstraint(name: 'uniq_task_execution_attempt_execution_number', columns: ['execution_id', 'attempt_number'])]
#[ORM\Index(columns: ['execution_id'], name: 'idx_task_execution_attempt_execution')]
class TaskExecutionAttempt
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: TaskExecution::class, inversedBy: 'attempts')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TaskExecution $execution;

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

    public function __construct(TaskExecution $execution, int $attemptNumber)
    {
        $this->id = Uuid::v7();
        $this->execution = $execution;
        $this->attemptNumber = max(1, $attemptNumber);
        $this->createdAt = new \DateTimeImmutable();
        $execution->addAttempt($this);
    }

    public function getId(): Uuid { return $this->id; }
    public function getExecution(): TaskExecution { return $this->execution; }
    public function getAttemptNumber(): int { return $this->attemptNumber; }
    public function getAgent(): ?Agent { return $this->agent; }
    public function getMessengerReceiver(): ?string { return $this->messengerReceiver; }
    public function getRequestRef(): ?string { return $this->requestRef; }
    public function getStatus(): TaskExecutionAttemptStatus { return $this->status; }
    public function willRetry(): bool { return $this->willRetry; }
    public function getStartedAt(): ?\DateTimeImmutable { return $this->startedAt; }
    public function getFinishedAt(): ?\DateTimeImmutable { return $this->finishedAt; }
    public function getErrorMessage(): ?string { return $this->errorMessage; }
    public function getErrorScope(): ?string { return $this->errorScope; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function setAgent(?Agent $agent): static { $this->agent = $agent; return $this; }
    public function setMessengerReceiver(?string $messengerReceiver): static { $this->messengerReceiver = $messengerReceiver; return $this; }
    public function setRequestRef(?string $requestRef): static { $this->requestRef = $requestRef; return $this; }
    public function setStatus(TaskExecutionAttemptStatus $status): static { $this->status = $status; return $this; }
    public function setWillRetry(bool $willRetry): static { $this->willRetry = $willRetry; return $this; }
    public function setStartedAt(?\DateTimeImmutable $startedAt): static { $this->startedAt = $startedAt; return $this; }
    public function setFinishedAt(?\DateTimeImmutable $finishedAt): static { $this->finishedAt = $finishedAt; return $this; }
    public function setErrorMessage(?string $errorMessage): static { $this->errorMessage = $errorMessage; return $this; }
    public function setErrorScope(?string $errorScope): static { $this->errorScope = $errorScope; return $this; }
}
