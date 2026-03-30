<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Service;

use App\Entity\Feature;
use App\Entity\Project;
use App\Entity\Ticket;
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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class TicketService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TicketRepository $ticketRepository,
        private readonly FeatureRepository $featureRepository,
        private readonly RoleRepository $roleRepository,
        private readonly WorkflowStepRepository $workflowStepRepository,
        private readonly WorkflowStepActionRepository $workflowStepActionRepository,
        private readonly TicketTaskService $ticketTaskService,
        private readonly AuditService $audit,
    ) {}

    public function create(
        Project $project,
        TaskType $type,
        string $title,
        ?string $description = null,
        TaskPriority $priority = TaskPriority::Medium,
        ?string $featureId = null,
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

        $this->em->persist($ticket);
        $this->em->flush();

        $this->createWorkflowSeedTasks($ticket);

        $this->audit->log(AuditAction::TaskCreated, 'Ticket', (string) $ticket->getId(), [
            'title' => $title,
            'type' => $type->value,
            'project' => (string) $project->getId(),
        ]);

        return $ticket;
    }

    public function update(
        Ticket $ticket,
        string $title,
        ?string $description,
        TaskPriority $priority,
        ?string $featureId,
    ): Ticket {
        $ticket
            ->setTitle($title)
            ->setDescription($description)
            ->setPriority($priority);

        $feature = $featureId ? $this->featureRepository->find(Uuid::fromString($featureId)) : null;
        $ticket->setFeature($feature);

        $this->em->flush();

        $this->audit->log(AuditAction::TaskUpdated, 'Ticket', (string) $ticket->getId());

        return $ticket;
    }

    public function changeStatus(Ticket $ticket, TaskStatus $status): Ticket
    {
        $previous = $ticket->getStatus();
        $ticket->setStatus($status);
        $this->em->flush();

        $this->audit->log(AuditAction::TaskStatusChanged, 'Ticket', (string) $ticket->getId(), [
            'from' => $previous->value,
            'to' => $status->value,
        ]);

        return $ticket;
    }

    public function advanceWorkflowStep(Ticket $ticket): Ticket
    {
        $currentStep = $ticket->getWorkflowStep();
        if ($currentStep === null) {
            throw new \LogicException('This ticket is not attached to any workflow step.');
        }

        if ($currentStep->getTransitionMode()->value !== 'manual') {
            throw new \LogicException('Only manual workflow steps can be advanced explicitly.');
        }

        $nextStep = $this->workflowStepRepository->findNextByWorkflowStep($currentStep);
        if ($nextStep === null) {
            throw new \LogicException('This workflow step has no next step.');
        }

        $ticket->setWorkflowStep($nextStep);
        $this->em->flush();

        $this->audit->log(AuditAction::TaskUpdated, 'Ticket', (string) $ticket->getId(), [
            'workflow_step' => $nextStep->getKey(),
        ]);

        return $ticket;
    }

    public function delete(Ticket $ticket): void
    {
        $id = (string) $ticket->getId();
        $this->em->remove($ticket);
        $this->em->flush();

        $this->audit->log(AuditAction::TaskDeleted, 'Ticket', $id);
    }

    /** @return Ticket[] */
    public function findByProject(Project $project): array
    {
        return $this->ticketRepository->findByProject($project);
    }

    /** @return Ticket[] */
    public function findByFeature(Feature $feature): array
    {
        return $this->ticketRepository->findBy(['feature' => $feature], ['createdAt' => 'DESC']);
    }

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

        $nextStep = $this->workflowStepRepository->findNextByWorkflowStep($currentStep);

        return $nextStep !== null ? [$nextStep] : [];
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
