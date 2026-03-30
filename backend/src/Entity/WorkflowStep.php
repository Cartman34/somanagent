<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Entity;

use App\Enum\WorkflowStepTransitionMode;
use App\Enum\WorkflowStepStatus;
use App\Repository\WorkflowStepRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: WorkflowStepRepository::class)]
#[ORM\Table(name: 'workflow_step')]
class WorkflowStep
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Workflow::class, inversedBy: 'steps')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Workflow $workflow;

    #[ORM\Column]
    private int $stepOrder;

    #[ORM\Column(length: 255)]
    private string $name;

    /**
     * Configuration de l'entrée : source (vcs, previous_step, manual), paramètres.
     */
    #[ORM\Column(type: 'json')]
    private array $inputConfig = [];

    /**
     * Clé de l'output produit par cette étape (utilisable par les étapes suivantes).
     */
    #[ORM\Column(length: 255)]
    private string $outputKey;

    #[ORM\Column(enumType: WorkflowStepTransitionMode::class)]
    private WorkflowStepTransitionMode $transitionMode = WorkflowStepTransitionMode::Manual;

    /**
     * Condition d'exécution (ex: "previous.issues_count > 0"). Null = toujours exécuté.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $condition = null;

    /**
     * Statut de la dernière exécution.
     */
    #[ORM\Column(enumType: WorkflowStepStatus::class)]
    private WorkflowStepStatus $status;

    /**
     * Output de la dernière exécution (texte brut ou JSON).
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastOutput = null;

    /** @var Collection<int, WorkflowStepAction> */
    #[ORM\OneToMany(targetEntity: WorkflowStepAction::class, mappedBy: 'workflowStep', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $actions;

    public function __construct(
        Workflow $workflow,
        int      $stepOrder,
        string   $name,
        string   $outputKey,
    ) {
        $this->id        = Uuid::v7();
        $this->workflow  = $workflow;
        $this->stepOrder = $stepOrder;
        $this->name      = $name;
        $this->outputKey = $outputKey;
        $this->status    = WorkflowStepStatus::Pending;
        $this->actions   = new ArrayCollection();
    }

    public function getId(): Uuid                      { return $this->id; }
    public function getWorkflow(): Workflow            { return $this->workflow; }
    public function getStepOrder(): int                { return $this->stepOrder; }
    public function getName(): string                  { return $this->name; }
    public function getKey(): string                   { return $this->outputKey; }
    public function getInputConfig(): array            { return $this->inputConfig; }
    public function getOutputKey(): string             { return $this->outputKey; }
    public function getTransitionMode(): WorkflowStepTransitionMode { return $this->transitionMode; }
    public function getCondition(): ?string             { return $this->condition; }
    public function getStatus(): WorkflowStepStatus    { return $this->status; }
    public function getLastOutput(): ?string            { return $this->lastOutput; }
    /** @return Collection<int, WorkflowStepAction> */
    public function getActions(): Collection           { return $this->actions; }

    public function setWorkflow(Workflow $w): static              { $this->workflow = $w; return $this; }
    public function setStepOrder(int $order): static              { $this->stepOrder = $order; return $this; }
    public function setName(string $name): static                 { $this->name = $name; return $this; }
    public function setInputConfig(array $config): static         { $this->inputConfig = $config; return $this; }
    public function setOutputKey(string $key): static             { $this->outputKey = $key; return $this; }
    public function setTransitionMode(WorkflowStepTransitionMode $transitionMode): static { $this->transitionMode = $transitionMode; return $this; }
    public function setCondition(?string $cond): static                    { $this->condition = $cond; return $this; }
    public function setStatus(WorkflowStepStatus $s): static               { $this->status = $s; return $this; }
    public function setLastOutput(?string $output): static                 { $this->lastOutput = $output; return $this; }

    public function addAction(WorkflowStepAction $action): static
    {
        if (!$this->actions->contains($action)) {
            $this->actions->add($action);
            $action->setWorkflowStep($this);
        }

        return $this;
    }

    public function removeAction(WorkflowStepAction $action): static
    {
        $this->actions->removeElement($action);

        return $this;
    }

    public function markRunning(): static   { return $this->setStatus(WorkflowStepStatus::Running); }
    public function markDone(string $output): static
    {
        $this->status     = WorkflowStepStatus::Done;
        $this->lastOutput = $output;
        return $this;
    }
    public function markError(string $error): static
    {
        $this->status     = WorkflowStepStatus::Error;
        $this->lastOutput = $error;
        return $this;
    }
    public function markSkipped(): static  { return $this->setStatus(WorkflowStepStatus::Skipped); }
}
