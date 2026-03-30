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

    #[ORM\ManyToOne(targetEntity: Ticket::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Ticket $ticket = null;

    #[ORM\ManyToOne(targetEntity: TicketTask::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?TicketTask $ticketTask = null;

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
        ?Ticket       $ticket       = null,
        ?TicketTask   $ticketTask   = null,
        ?WorkflowStep $workflowStep = null,
    ) {
        $this->id           = Uuid::v7();
        $this->agent        = $agent;
        $this->model        = $model;
        $this->inputTokens  = $inputTokens;
        $this->outputTokens = $outputTokens;
        $this->durationMs   = $durationMs;
        $this->ticket       = $ticket;
        $this->ticketTask   = $ticketTask;
        $this->workflowStep = $workflowStep;
        $this->createdAt    = new \DateTimeImmutable();
    }

    public function getId(): Uuid                      { return $this->id; }
    public function getAgent(): ?Agent                 { return $this->agent; }
    public function getTicket(): ?Ticket               { return $this->ticket; }
    public function getTicketTask(): ?TicketTask       { return $this->ticketTask; }
    public function getWorkflowStep(): ?WorkflowStep   { return $this->workflowStep; }
    public function getModel(): string                 { return $this->model; }
    public function getInputTokens(): int              { return $this->inputTokens; }
    public function getOutputTokens(): int             { return $this->outputTokens; }
    public function getTotalTokens(): int              { return $this->inputTokens + $this->outputTokens; }
    public function getDurationMs(): ?int              { return $this->durationMs; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
