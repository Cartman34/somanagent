<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TokenUsageRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Records token consumption (input/output) for an agent call, optionally linked to a ticket, task, or workflow step.
 */
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

    /**
     * Creates a token usage record for an agent call and optional related entities.
     */
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

    /** Returns the token usage identifier. */
    public function getId(): Uuid                      { return $this->id; }
    /** Returns the related agent, if any. */
    public function getAgent(): ?Agent                 { return $this->agent; }
    /** Returns the related ticket, if any. */
    public function getTicket(): ?Ticket               { return $this->ticket; }
    /** Returns the related ticket task, if any. */
    public function getTicketTask(): ?TicketTask       { return $this->ticketTask; }
    /** Returns the related workflow step, if any. */
    public function getWorkflowStep(): ?WorkflowStep   { return $this->workflowStep; }
    /** Returns the model name used for the call. */
    public function getModel(): string                 { return $this->model; }
    /** Returns the input token count. */
    public function getInputTokens(): int              { return $this->inputTokens; }
    /** Returns the output token count. */
    public function getOutputTokens(): int             { return $this->outputTokens; }
    /** Returns the total number of consumed tokens. */
    public function getTotalTokens(): int              { return $this->inputTokens + $this->outputTokens; }
    /** Returns the call duration in milliseconds, if available. */
    public function getDurationMs(): ?int              { return $this->durationMs; }
    /** Returns when the token usage record was created. */
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
