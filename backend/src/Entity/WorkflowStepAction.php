<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WorkflowStepActionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: WorkflowStepActionRepository::class)]
#[ORM\Table(name: 'workflow_step_action')]
#[ORM\UniqueConstraint(name: 'uniq_workflow_step_action_step_action', columns: ['workflow_step_id', 'agent_action_id'])]
class WorkflowStepAction
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: WorkflowStep::class, inversedBy: 'actions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private WorkflowStep $workflowStep;

    #[ORM\ManyToOne(targetEntity: AgentAction::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private AgentAction $agentAction;

    #[ORM\Column(options: ['default' => false])]
    private bool $createWithTicket = false;

    public function __construct(WorkflowStep $workflowStep, AgentAction $agentAction)
    {
        $this->id = Uuid::v7();
        $this->workflowStep = $workflowStep;
        $this->agentAction = $agentAction;
    }

    public function getId(): Uuid { return $this->id; }
    public function getWorkflowStep(): WorkflowStep { return $this->workflowStep; }
    public function getAgentAction(): AgentAction { return $this->agentAction; }
    public function shouldCreateWithTicket(): bool { return $this->createWithTicket; }

    public function setWorkflowStep(WorkflowStep $workflowStep): static
    {
        $this->workflowStep = $workflowStep;

        return $this;
    }

    public function setAgentAction(AgentAction $agentAction): static
    {
        $this->agentAction = $agentAction;

        return $this;
    }

    public function setCreateWithTicket(bool $createWithTicket): static
    {
        $this->createWithTicket = $createWithTicket;

        return $this;
    }
}
