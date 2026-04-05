<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Service;

use App\Entity\Workflow;
use App\Entity\WorkflowStep;
use App\Entity\WorkflowStepAction;
use App\Enum\AuditAction;
use App\Enum\WorkflowTrigger;
use App\Repository\WorkflowRepository;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

class WorkflowService
{
    /**
     * Initializes the service with its dependencies.
     */
    public function __construct(
        private readonly EntityService      $entityService,
        private readonly WorkflowRepository $workflowRepository,
        private readonly TranslatorInterface $translator,
    ) {}

    /**
     * Creates a new active immutable workflow definition.
     */
    public function create(
        string          $name,
        WorkflowTrigger $trigger     = WorkflowTrigger::Manual,
        ?string         $description = null,
    ): Workflow {
        $workflow = new Workflow($name, $trigger, $description);
        $workflow->validate();
        $this->entityService->create($workflow, AuditAction::WorkflowCreated, ['name' => $name]);

        return $workflow;
    }

    /**
     * Creates an inactive editable copy of an existing workflow with cloned step definitions.
     */
    public function duplicate(Workflow $workflow): Workflow
    {
        $duplicate = new Workflow(
            $this->translator->trans('workflow.duplication.copy_name', ['%name%' => $workflow->getName()], 'app'),
            $workflow->getTrigger(),
            $workflow->getDescription(),
        );

        foreach ($workflow->getSteps() as $step) {
            $duplicateStep = new WorkflowStep(
                $duplicate,
                $step->getStepOrder(),
                $step->getName(),
                $step->getOutputKey(),
            );
            $duplicateStep
                ->setInputConfig($step->getInputConfig())
                ->setTransitionMode($step->getTransitionMode())
                ->setCondition($step->getCondition());

            foreach ($step->getActions() as $action) {
                $duplicateStep->addAction(
                    (new WorkflowStepAction($duplicateStep, $action->getAgentAction()))
                        ->setCreateWithTicket($action->shouldCreateWithTicket())
                );
            }

            $duplicate->addStep($duplicateStep);
        }

        $duplicate->validate();
        $duplicate->deactivate();

        $this->entityService->create($duplicate, AuditAction::WorkflowCreated, [
            'name'             => $duplicate->getName(),
            'duplicatedFromId' => (string) $workflow->getId(),
        ]);

        return $duplicate;
    }

    /**
     * Activates a workflow so it can be resolved by runtime services.
     */
    public function activate(Workflow $workflow): Workflow
    {
        $workflow->activate();
        $this->entityService->update($workflow, AuditAction::WorkflowUpdated, [
            'isActive' => true,
            'status'   => $workflow->getStatus()->value,
        ]);

        return $workflow;
    }

    /**
     * Deactivates a workflow when it has not been used yet.
     */
    public function deactivate(Workflow $workflow): Workflow
    {
        if ($this->workflowRepository->hasUsage($workflow)) {
            throw new \LogicException($this->translator->trans('workflow.error.deactivation_used', domain: 'app'));
        }

        $workflow->deactivate();
        $this->entityService->update($workflow, AuditAction::WorkflowUpdated, ['isActive' => false]);

        return $workflow;
    }

    /**
     * Updates a workflow's name, trigger, and description.
     */
    public function update(
        Workflow        $workflow,
        string          $name,
        WorkflowTrigger $trigger,
        ?string         $description = null,
    ): Workflow {
        $workflow->setName($name)
            ->setTrigger($trigger)
            ->setDescription($description);

        $this->entityService->update($workflow, AuditAction::WorkflowUpdated);

        return $workflow;
    }

    /**
     * Deletes a workflow and records the audit event.
     */
    public function delete(Workflow $workflow): void
    {
        $this->entityService->delete($workflow, AuditAction::WorkflowDeleted);
    }

    /**
     * Returns all workflows ordered by creation date descending.
     *
     * @return Workflow[]
     */
    public function findAll(): array
    {
        return $this->workflowRepository->findBy([], ['createdAt' => 'DESC']);
    }

    /**
     * Finds a workflow by its UUID string identifier.
     */
    public function findById(string $id): ?Workflow
    {
        return $this->workflowRepository->find(Uuid::fromString($id));
    }

    /**
     * Returns whether the workflow can still be edited or deleted safely.
     */
    public function canEdit(Workflow $workflow): bool
    {
        return $workflow->isEditable() && !$this->workflowRepository->hasProjectReferences($workflow);
    }
}
