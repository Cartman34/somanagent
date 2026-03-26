<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TokenUsageRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TokenUsageRepository::class)]
#[ORM\Table(name: 'token_usage')]
class TokenUsage
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Agent::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Agent $agent;

    #[ORM\ManyToOne(targetEntity: Task::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Task $task = null;

    #[ORM\ManyToOne(targetEntity: WorkflowStep::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?WorkflowStep $workflowStep = null;

    #[ORM\Column(length: 100)]
    private string $model;

    #[ORM\Column(type: 'integer')]
    private int $inputTokens;

    #[ORM\Column(type: 'integer')]
    private int $outputTokens;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $durationMs = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        ?Agent        $agent,
        string        $model,
        int           $inputTokens,
        int           $outputTokens,
        ?int          $durationMs   = null,
        ?Task         $task         = null,
        ?WorkflowStep $workflowStep = null,
    ) {
        $this->id           = Uuid::v7();
        $this->agent        = $agent;
        $this->model        = $model;
        $this->inputTokens  = $inputTokens;
        $this->outputTokens = $outputTokens;
        $this->durationMs   = $durationMs;
        $this->task         = $task;
        $this->workflowStep = $workflowStep;
        $this->createdAt    = new \DateTimeImmutable();
    }

    public function getId(): Uuid                      { return $this->id; }
    public function getAgent(): ?Agent                 { return $this->agent; }
    public function getTask(): ?Task                   { return $this->task; }
    public function getWorkflowStep(): ?WorkflowStep   { return $this->workflowStep; }
    public function getModel(): string                 { return $this->model; }
    public function getInputTokens(): int              { return $this->inputTokens; }
    public function getOutputTokens(): int             { return $this->outputTokens; }
    public function getTotalTokens(): int              { return $this->inputTokens + $this->outputTokens; }
    public function getDurationMs(): ?int              { return $this->durationMs; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
