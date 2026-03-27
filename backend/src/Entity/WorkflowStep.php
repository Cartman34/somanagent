<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\StoryStatus;
use App\Enum\WorkflowStepStatus;
use App\Repository\WorkflowStepRepository;
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
     * Slug du rôle dans l'équipe qui traite cette étape.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $roleSlug = null;

    /**
     * Slug du skill à utiliser pour cette étape.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $skillSlug = null;

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
     * Story status that triggers this step in the story lifecycle.
     * When set, StoryExecutionService uses this step's roleSlug/skillSlug instead of
     * the hardcoded EXECUTION_MAP, scoped to the project's team workflow.
     * Null = this step is not part of the story lifecycle automation.
     */
    #[ORM\Column(enumType: StoryStatus::class, nullable: true)]
    private ?StoryStatus $storyStatusTrigger = null;

    /**
     * Output de la dernière exécution (texte brut ou JSON).
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastOutput = null;

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
    }

    public function getId(): Uuid                      { return $this->id; }
    public function getWorkflow(): Workflow            { return $this->workflow; }
    public function getStepOrder(): int                { return $this->stepOrder; }
    public function getName(): string                  { return $this->name; }
    public function getRoleSlug(): ?string             { return $this->roleSlug; }
    public function getSkillSlug(): ?string            { return $this->skillSlug; }
    public function getInputConfig(): array            { return $this->inputConfig; }
    public function getOutputKey(): string             { return $this->outputKey; }
    public function getCondition(): ?string             { return $this->condition; }
    public function getStatus(): WorkflowStepStatus    { return $this->status; }
    public function getStoryStatusTrigger(): ?StoryStatus { return $this->storyStatusTrigger; }
    public function getLastOutput(): ?string            { return $this->lastOutput; }

    public function setWorkflow(Workflow $w): static              { $this->workflow = $w; return $this; }
    public function setStepOrder(int $order): static              { $this->stepOrder = $order; return $this; }
    public function setName(string $name): static                 { $this->name = $name; return $this; }
    public function setRoleSlug(?string $slug): static            { $this->roleSlug = $slug; return $this; }
    public function setSkillSlug(?string $slug): static           { $this->skillSlug = $slug; return $this; }
    public function setInputConfig(array $config): static         { $this->inputConfig = $config; return $this; }
    public function setOutputKey(string $key): static             { $this->outputKey = $key; return $this; }
    public function setCondition(?string $cond): static                    { $this->condition = $cond; return $this; }
    public function setStatus(WorkflowStepStatus $s): static               { $this->status = $s; return $this; }
    public function setStoryStatusTrigger(?StoryStatus $s): static         { $this->storyStatusTrigger = $s; return $this; }
    public function setLastOutput(?string $output): static                 { $this->lastOutput = $output; return $this; }

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
