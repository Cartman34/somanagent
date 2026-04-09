<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Service;

use App\Entity\Feature;
use App\Entity\Project;
use App\Entity\Ticket;
use App\Entity\TicketTask;
use App\Entity\Role;
use App\Entity\WorkflowStep;
use App\Entity\WorkflowStepAction;
use App\Enum\AuditAction;
use App\Enum\TaskPriority;
use App\Enum\TaskStatus;
use App\Enum\TaskType;
use App\Repository\FeatureRepository;
use App\Repository\RoleRepository;
use App\Repository\TicketRepository;
use App\Repository\WorkflowStepActionRepository;
use App\Repository\WorkflowStepRepository;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Manages tickets: CRUD, status transitions, workflow step progression, and feature association.
 */
final class TicketService
{
    /**
     * Inject service dependencies.
     */
    public function __construct(
        private readonly EntityService                $entityService,
        private readonly TicketRepository             $ticketRepository,
        private readonly FeatureRepository            $featureRepository,
        private readonly RoleRepository               $roleRepository,
        private readonly WorkflowStepRepository       $workflowStepRepository,
        private readonly WorkflowStepActionRepository $workflowStepActionRepository,
        private readonly TicketTaskService            $ticketTaskService,
        private readonly TicketLogService             $ticketLogService,
        private readonly RealtimeUpdateService        $realtimeUpdateService,
        private readonly TranslatorInterface          $translator,
    ) {}

    /**
     * Create a new ticket and initialize its workflow tasks.
     */
    public function create(
        Project       $project,
        TaskType      $type,
        string        $title,
        ?string       $description = null,
        TaskPriority  $priority    = TaskPriority::Medium,
        ?string       $featureId   = null,
    ): Ticket {
        $ticket = new Ticket($project, $type, $title, $description, $priority);

        if ($type === TaskType::UserStory || $type === TaskType::Bug) {
            /** @var Role|null $productOwnerRole */
            $productOwnerRole = $this->roleRepository->findOneBy(['slug' => 'product-owner']);
            if ($productOwnerRole !== null) {
                $ticket->setAssignedRole($productOwnerRole);
            }
        }

        if ($featureId !== null) {
            $feature = $this->featureRepository->find(Uuid::fromString($featureId));
            if ($feature !== null) {
                $ticket->setFeature($feature);
            }
        }

        $this->initializeCurrentWorkflowStep($ticket);

        $this->entityService->create($ticket, AuditAction::TaskCreated, [
            'title'   => $title,
            'type'    => $type->value,
            'project' => (string) $project->getId(),
        ]);

        $this->createWorkflowSeedTasks($ticket);
        $this->realtimeUpdateService->publishTicketChanged($ticket, 'created');

        return $ticket;
    }

    /**
     * Update the ticket's title, description, priority, and feature.
     */
    public function update(
        Ticket       $ticket,
        string       $title,
        ?string      $description,
        TaskPriority $priority,
        ?string      $featureId,
    ): Ticket {
        $ticket
            ->setTitle($title)
            ->setDescription($description)
            ->setPriority($priority);

        $feature = $featureId ? $this->featureRepository->find(Uuid::fromString($featureId)) : null;
        $ticket->setFeature($feature);

        $this->entityService->update($ticket, AuditAction::TaskUpdated);
        $this->realtimeUpdateService->publishTicketChanged($ticket, 'updated');

        return $ticket;
    }

    /**
     * Change the status of a ticket and record the transition.
     */
    public function changeStatus(Ticket $ticket, TaskStatus $status): Ticket
    {
        $previous = $ticket->getStatus();
        $ticket->setStatus($status);

        $this->entityService->update($ticket, AuditAction::TaskStatusChanged, [
            'from' => $previous->value,
            'to'   => $status->value,
        ]);
        $this->realtimeUpdateService->publishTicketChanged($ticket, 'status_changed', [
            'status' => $status->value,
        ]);

        return $ticket;
    }

    /**
     * Manually advance the ticket to the next workflow step.
     */
    public function advanceWorkflowStep(Ticket $ticket): Ticket
    {
        $currentStep = $ticket->getWorkflowStep();
        if ($currentStep === null) {
            throw new \LogicException('This ticket is not attached to any workflow step.');
        }

        if ($currentStep->getTransitionMode()->value !== 'manual') {
            throw new \LogicException('Only manual workflow steps can be advanced explicitly.');
        }

        $currentStepTasks = array_filter(
            $this->ticketTaskService->findByTicket($ticket),
            static fn(TicketTask $task): bool => $task->getWorkflowStep()?->getId()?->toRfc4122() === $currentStep->getId()->toRfc4122(),
        );

        $this->assertAdvanceableCurrentStepTasks($currentStepTasks);

        $nextStep = $this->workflowStepRepository->findNextByWorkflowStep($currentStep);
        if ($nextStep === null) {
            throw new \LogicException('This workflow step has no next step.');
        }

        $ticket->setWorkflowStep($nextStep);

        $this->entityService->update($ticket, AuditAction::TaskUpdated, [
            'workflow_step' => $nextStep->getKey(),
        ]);
        $this->realtimeUpdateService->publishTicketChanged($ticket, 'workflow_step_changed', [
            'workflowStepKey' => $nextStep->getKey(),
        ]);

        return $ticket;
    }

    /**
     * Delete a ticket and log the deletion.
     */
    public function delete(Ticket $ticket): void
    {
        $project = $ticket->getProject();
        $ticketId = (string) $ticket->getId();
        $this->entityService->delete($ticket, AuditAction::TaskDeleted);
        $this->realtimeUpdateService->publishTicketDeleted($project, $ticketId);
    }

    /**
     * @return Ticket[]
     */
    public function findByProject(Project $project): array
    {
        return $this->ticketRepository->findByProject($project);
    }

    /**
     * @return Ticket[]
     */
    public function findByFeature(Feature $feature): array
    {
        return $this->ticketRepository->findBy(['feature' => $feature], ['createdAt' => 'DESC']);
    }

    /**
     * Find a ticket by its UUID string, or return null if not found.
     */
    public function findById(string $id): ?Ticket
    {
        return $this->ticketRepository->find(Uuid::fromString($id));
    }

    /**
     * @return WorkflowStep[]
     */
    public function findAllowedManualTransitions(Ticket $ticket): array
    {
        $currentStep = $ticket->getWorkflowStep();
        if ($currentStep === null || $currentStep->getTransitionMode()->value !== 'manual') {
            return [];
        }

        if (!$this->ticketTaskService->describeWorkflowStepProgress($ticket)['canAdvance']) {
            return [];
        }

        $nextStep = $this->workflowStepRepository->findNextByWorkflowStep($currentStep);

        return $nextStep !== null ? [$nextStep] : [];
    }

    /**
     * @param TicketTask[] $currentStepTasks
     */
    private function assertAdvanceableCurrentStepTasks(array $currentStepTasks): void
    {
        foreach ($currentStepTasks as $task) {
            if ($this->ticketTaskService->hasActiveExecution($task)) {
                throw new \LogicException($this->translator->trans('ticket.error.active_execution_before_workflow_transition', [], 'app'));
            }

            if ($this->ticketLogService->countPendingBlockingAnswersForTask($task) > 0) {
                throw new \LogicException($this->translator->trans('ticket.error.pending_answers_before_workflow_transition', [], 'app'));
            }

            if ($task->getStatus()->isDone()) {
                continue;
            }

            throw new \LogicException($this->translator->trans('ticket.error.current_step_tasks_incomplete_before_workflow_transition', [], 'app'));
        }
    }

    private function initializeCurrentWorkflowStep(Ticket $ticket): void
    {
        $workflow = $ticket->getProject()->getWorkflow();
        if ($workflow === null) {
            $ticket->setWorkflowStep(null);
            return;
        }

        $ticket->setWorkflowStep($this->workflowStepRepository->findFirstByWorkflow($workflow));
    }

    private function createWorkflowSeedTasks(Ticket $ticket): void
    {
        $workflow = $ticket->getProject()->getWorkflow();
        if ($workflow === null) {
            return;
        }

        foreach ($this->workflowStepActionRepository->findCreateWithTicketByWorkflow($workflow) as $workflowStepAction) {
            if (!$workflowStepAction instanceof WorkflowStepAction) {
                continue;
            }

            $this->ticketTaskService->ensureCreateWithTicketTask($ticket, $workflowStepAction);
        }
    }
}
