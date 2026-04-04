<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Service;

use App\Entity\Agent;
use App\Entity\AgentAction;
use App\Entity\Ticket;
use App\Entity\TicketTask;
use App\Entity\TicketTaskDependency;
use App\Entity\WorkflowStep;
use App\Entity\WorkflowStepAction;
use App\Enum\AuditAction;
use App\Enum\TaskExecutionTrigger;
use App\Enum\TaskPriority;
use App\Enum\TaskStatus;
use App\Enum\WorkflowStepTransitionMode;
use App\Message\AgentTaskMessage;
use App\Repository\AgentActionRepository;
use App\Repository\AgentRepository;
use App\Repository\AgentTaskExecutionRepository;
use App\Repository\TicketTaskDependencyRepository;
use App\Repository\TicketTaskRepository;
use App\Repository\WorkflowStepActionRepository;
use App\Repository\WorkflowStepRepository;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

final class TicketTaskService
{
    /**
     * Inject service dependencies.
     */
    public function __construct(
        private readonly EntityService $entityService,
        private readonly TicketTaskRepository $ticketTaskRepository,
        private readonly TicketTaskDependencyRepository $ticketTaskDependencyRepository,
        private readonly AgentTaskExecutionRepository $agentTaskExecutionRepository,
        private readonly AgentActionRepository $agentActionRepository,
        private readonly AgentRepository $agentRepository,
        private readonly WorkflowStepActionRepository $workflowStepActionRepository,
        private readonly WorkflowStepRepository $workflowStepRepository,
        private readonly AgentTaskExecutionService $agentTaskExecutionService,
        private readonly TicketLogService $ticketLogService,
        private readonly RequestCorrelationService $requestCorrelation,
        private readonly MessageBusInterface $bus,
        private readonly TranslatorInterface $translator,
    ) {}

    /**
     * Create a new ticket task, resolve its agent, and persist it.
     */
    public function create(
        Ticket $ticket,
        string $actionKey,
        string $title,
        ?string $description = null,
        TaskPriority $priority = TaskPriority::Medium,
        ?string $parentId = null,
        ?string $assignedAgentId = null,
    ): TicketTask {
        $action = $this->requireAction($actionKey);
        $task = new TicketTask($ticket, $action, $title, $description, $priority);
        $task
            ->setAssignedRole($action->getRole())
            ->setWorkflowStep($this->resolveWorkflowStepForAction($ticket, $action));

        if ($parentId !== null) {
            $parent = $this->ticketTaskRepository->find(Uuid::fromString($parentId));
            if ($parent !== null) {
                $task->setParent($parent);
            }
        }

        if ($assignedAgentId !== null) {
            $agent = $this->agentRepository->find(Uuid::fromString($assignedAgentId));
            if ($agent !== null) {
                $task->setAssignedAgent($agent);
            }
        }

        if ($task->getAssignedAgent() === null) {
            $task->setAssignedAgent($this->resolveAgentForTask($task));
        }

        $this->entityService->create($task, AuditAction::TaskCreated, [
            'title'     => $title,
            'ticket'    => (string) $ticket->getId(),
            'actionKey' => $action->getKey(),
        ]);

        return $task;
    }

    /**
     * Update a ticket task's fields, action, and assigned agent.
     */
    public function update(
        TicketTask $task,
        string $title,
        ?string $description,
        TaskPriority $priority,
        ?string $actionKey,
        ?string $assignedAgentId,
    ): TicketTask {
        $task
            ->setTitle($title)
            ->setDescription($description)
            ->setPriority($priority);

        if ($actionKey !== null && $actionKey !== '') {
            $action = $this->requireAction($actionKey);
            $task
                ->setAgentAction($action)
                ->setAssignedRole($action->getRole())
                ->setWorkflowStep($this->resolveWorkflowStepForAction($task->getTicket(), $action))
                ->setAssignedAgent(null);
        }

        $agent = $assignedAgentId ? $this->agentRepository->find(Uuid::fromString($assignedAgentId)) : null;
        $task->setAssignedAgent($agent ?? $this->resolveAgentForTask($task));

        $this->entityService->update($task, AuditAction::TaskUpdated);

        return $task;
    }

    /**
     * @return array{agent: Agent, skill: string, executionId: string}
     */
    public function execute(TicketTask $task, ?Agent $agent = null, TaskExecutionTrigger $triggerType = TaskExecutionTrigger::Manual): array
    {
        $activeExecution = $this->agentTaskExecutionRepository->findActiveByTicketTask($task);
        if ($activeExecution !== null) {
            throw new \RuntimeException('An agent execution is already active for this task.');
        }

        $skillSlug = $task->getAgentAction()->getSkill()?->getSlug();
        if ($skillSlug === null) {
            throw new \RuntimeException(sprintf('Action "%s" defines no executable skill.', $task->getAgentAction()->getKey()));
        }

        $resolvedAgent = $agent ?? $task->getAssignedAgent() ?? $this->resolveAgentForTask($task);
        if ($resolvedAgent === null) {
            throw new \RuntimeException(sprintf('No active agent available for action "%s".', $task->getAgentAction()->getKey()));
        }

        $requestRef = $this->requestCorrelation->getCurrentRequestRef();
        $traceRef = Uuid::v7()->toRfc4122();
        $hasExecutionHistory = !$task->getExecutions()->isEmpty();

        $task
            ->setAssignedRole($task->getAgentAction()->getRole())
            ->setAssignedAgent($resolvedAgent)
            ->setStatus(TaskStatus::InProgress);

        if ($task->getStatus() !== TaskStatus::Done) {
            $task->setProgress(0);
        }

        $execution = $this->agentTaskExecutionService->createExecution(
            ticketTask: $task,
            requestedAgent: $resolvedAgent,
            triggerType: $triggerType,
            requestRef: $requestRef,
            traceRef: $traceRef,
        );

        $this->ticketLogService->log(
            ticket: $task->getTicket(),
            action: $triggerType === TaskExecutionTrigger::Manual && $hasExecutionHistory ? 'execution_redispatched' : 'execution_dispatched',
            content: sprintf(
                'Task dispatched to %s with skill %s',
                $resolvedAgent->getName(),
                $skillSlug,
            ),
            ticketTask: $task,
            metadata: [
                'agentId' => (string) $resolvedAgent->getId(),
                'agentName' => $resolvedAgent->getName(),
                'skillSlug' => $skillSlug,
                'roleSlug' => $task->getAgentAction()->getRole()?->getSlug(),
                'actionKey' => $task->getAgentAction()->getKey(),
                'executionId' => (string) $execution->getId(),
                'triggerType' => $triggerType->value,
            ],
        );

        $this->bus->dispatch(new AgentTaskMessage(
            ticketTaskId: (string) $task->getId(),
            agentId: (string) $resolvedAgent->getId(),
            skillSlug: $skillSlug,
            agentTaskExecutionId: (string) $execution->getId(),
            requestRef: $requestRef,
            traceRef: $traceRef,
        ));

        $this->entityService->flush();

        return [
            'agent' => $resolvedAgent,
            'skill' => $skillSlug,
            'executionId' => (string) $execution->getId(),
        ];
    }

    /**
     * @return array{agent: Agent, skill: string, executionId: string}
     */
    public function resume(TicketTask $task, ?Agent $agent = null): array
    {
        return $this->execute($task, $agent, TaskExecutionTrigger::Manual);
    }

    /**
     * Dispatch all tasks that became eligible after the given task completed.
     */
    public function dispatchReadyDependents(TicketTask $completedTask): int
    {
        if ($completedTask->getStatus() !== TaskStatus::Done) {
            return 0;
        }

        return $this->dispatchEligibleTasksForCurrentStep($completedTask->getTicket());
    }

    /**
     * Return whether the task currently has an active agent execution.
     */
    public function hasActiveExecution(TicketTask $task): bool
    {
        return $this->agentTaskExecutionRepository->findActiveByTicketTask($task) !== null;
    }

    /**
     * Dispatch all auto-executable tasks in the ticket's current workflow step.
     */
    public function dispatchEligibleTasksForCurrentStep(Ticket $ticket): int
    {
        $currentStep = $ticket->getWorkflowStep();
        if ($currentStep === null) {
            return 0;
        }

        $dispatched = 0;
        foreach ($this->findTasksForWorkflowStep($ticket, $currentStep) as $task) {
            if (!$this->isAutoExecutableInCurrentStep($task, $currentStep)) {
                continue;
            }

            try {
                $this->execute($task, triggerType: TaskExecutionTrigger::Auto);
                ++$dispatched;
            } catch (\Throwable $e) {
                $this->ticketLogService->log(
                    ticket: $ticket,
                    action: 'execution_dispatch_error',
                    content: $e->getMessage(),
                    ticketTask: $task,
                );
            }
        }

        $this->advanceTicketStepIfAutomatic($ticket);

        return $dispatched;
    }

    /**
     * Returns agents eligible to handle this task, filtered by skill and role.
     *
     * @return Agent[]
     */
    public function availableAgents(TicketTask $task): array
    {
        $action = $task->getAgentAction();
        $team = $task->getTicket()->getProject()->getTeam();
        $roleSlug = $action->getRole()?->getSlug();
        $skillSlug = $action->getSkill()?->getSlug();

        if ($skillSlug !== null) {
            $agents = $team !== null
                ? $this->agentRepository->findActiveBySkillSlugAndTeam($skillSlug, $team)
                : $this->agentRepository->findActiveBySkillSlug($skillSlug);

            if ($roleSlug !== null) {
                $agents = array_values(array_filter(
                    $agents,
                    static fn(Agent $agent): bool => $agent->getRole()?->getSlug() === $roleSlug,
                ));
            }

            if ($agents !== []) {
                return $agents;
            }
        }

        if ($roleSlug === null) {
            return [];
        }

        return $team !== null
            ? $this->agentRepository->findActiveByRoleSlugAndTeam($roleSlug, $team)
            : $this->agentRepository->findActiveByRoleSlug($roleSlug);
    }

    /**
     * Mark a task execution as failed and reset it to backlog status.
     */
    public function failExecution(TicketTask $task, string $message): TicketTask
    {
        $previous = $task->getStatus();
        $task
            ->setStatus(TaskStatus::Backlog)
            ->setProgress(0);

        if ($previous !== TaskStatus::Backlog) {
            $this->entityService->update($task, AuditAction::TaskStatusChanged, [
                'from'    => $previous->value,
                'to'      => TaskStatus::Backlog->value,
                'reason'  => 'execution_error',
                'message' => $message,
            ]);
        } else {
            $this->entityService->flush();
        }

        return $task;
    }

    /**
     * Change the status of a task and trigger dependent dispatch when done.
     */
    public function changeStatus(TicketTask $task, TaskStatus $status): TicketTask
    {
        $previous = $task->getStatus();
        $task->setStatus($status);
        if ($status === TaskStatus::Done) {
            $task->setProgress(100);
        }

        $this->entityService->update($task, AuditAction::TaskStatusChanged, [
            'from' => $previous->value,
            'to'   => $status->value,
        ]);

        if ($status === TaskStatus::Done) {
            $this->dispatchReadyDependents($task);
        }

        return $task;
    }

    /**
     * Update the progress percentage of a task.
     */
    public function updateProgress(TicketTask $task, int $progress): TicketTask
    {
        $task->setProgress($progress);
        $this->entityService->update($task, AuditAction::TaskProgressUpdated, ['progress' => $progress]);

        return $task;
    }

    /**
     * Change the priority of a task and record the transition.
     */
    public function reprioritize(TicketTask $task, TaskPriority $priority): TicketTask
    {
        $previous = $task->getPriority();
        $task->setPriority($priority);
        $this->entityService->update($task, AuditAction::TaskReprioritized, [
            'from' => $previous->value,
            'to'   => $priority->value,
        ]);

        return $task;
    }

    /**
     * Move a task to review status and notify that validation is requested.
     */
    public function requestValidation(TicketTask $task, ?string $comment = null): TicketTask
    {
        $task->setStatus(TaskStatus::Review);
        $this->entityService->update($task, AuditAction::TaskValidationAsked, ['comment' => $comment]);

        return $task;
    }

    /**
     * Validate a task, mark it as done, and dispatch ready dependents.
     */
    public function validate(TicketTask $task): TicketTask
    {
        $task->setStatus(TaskStatus::Done)->setProgress(100);
        $this->entityService->update($task, AuditAction::TaskValidated);

        $this->dispatchReadyDependents($task);

        return $task;
    }

    /**
     * Reject a validated task and return it to in-progress status.
     */
    public function reject(TicketTask $task, ?string $reason = null): TicketTask
    {
        $task->setStatus(TaskStatus::InProgress);
        $this->entityService->update($task, AuditAction::TaskRejected, ['reason' => $reason]);

        return $task;
    }

    /**
     * Record that a task depends on another task before it can proceed.
     */
    public function addDependency(TicketTask $task, TicketTask $dependsOn): TicketTaskDependency
    {
        $dependency = new TicketTaskDependency($task, $dependsOn);
        $this->entityService->create($dependency);

        return $dependency;
    }

    /**
     * @return TicketTask[]
     */
    public function findByTicket(Ticket $ticket): array
    {
        return $this->ticketTaskRepository->findByTicket($ticket);
    }

    /**
     * Returns only root-level tasks (tasks without a parent) for the ticket.
     *
     * @return TicketTask[]
     */
    public function findRootsByTicket(Ticket $ticket): array
    {
        return $this->ticketTaskRepository->findRootsByTicket($ticket);
    }

    /**
     * Returns the direct children of a task.
     *
     * @return TicketTask[]
     */
    public function findChildren(TicketTask $task): array
    {
        return $this->ticketTaskRepository->findChildren($task);
    }

    /**
     * @param TicketTask[] $tasks
     * @return array<string, TicketTask[]>
     */
    public function findChildrenGroupedByParent(array $tasks): array
    {
        return $this->ticketTaskRepository->findChildrenGroupedByParent($tasks);
    }

    /**
     * Finds a task by its UUID string identifier.
     */
    public function findById(string $id): ?TicketTask
    {
        return $this->ticketTaskRepository->find(Uuid::fromString($id));
    }

    /**
     * Returns the agent currently assigned to the task.
     */
    public function findAssignedAgent(TicketTask $task): ?Agent
    {
        return $task->getAssignedAgent();
    }

    /**
     * Deletes a task and records the audit event.
     */
    public function delete(TicketTask $task): void
    {
        $this->entityService->delete($task, AuditAction::TaskDeleted);
    }

    /**
     * Returns an existing task for the given workflow step action, or creates one if none exists.
     */
    public function ensureCreateWithTicketTask(Ticket $ticket, WorkflowStepAction $workflowStepAction): TicketTask
    {
        $existing = $this->ticketTaskRepository->findOneLatestByTicketAndWorkflowStepAndAction(
            $ticket,
            $workflowStepAction->getWorkflowStep(),
            $workflowStepAction->getAgentAction(),
        );

        if ($existing !== null) {
            return $existing;
        }

        $action = $workflowStepAction->getAgentAction();
        $task = new TicketTask(
            $ticket,
            $action,
            $this->buildCreateWithTicketTitle($workflowStepAction->getWorkflowStep(), $action),
            $action->getDescription(),
        );
        $task
            ->setWorkflowStep($workflowStepAction->getWorkflowStep())
            ->setAssignedRole($action->getRole())
            ->setAssignedAgent($this->resolveAgentForTask($task));

        $this->entityService->create($task, AuditAction::TaskCreated, [
            'title'            => $task->getTitle(),
            'ticket'           => (string) $ticket->getId(),
            'actionKey'        => $action->getKey(),
            'createWithTicket' => true,
        ]);

        return $task;
    }

    private function requireAction(string $actionKey): AgentAction
    {
        $action = $this->agentActionRepository->findOneByKey($actionKey);
        if ($action === null) {
            throw new \InvalidArgumentException(sprintf('Unknown agent action "%s".', $actionKey));
        }

        return $action;
    }

    /**
     * Resolves the workflow step that matches the given action within the ticket's workflow.
     */
    public function resolveWorkflowStepForAction(Ticket $ticket, AgentAction $action): ?\App\Entity\WorkflowStep
    {
        $workflow = $ticket->getProject()->getWorkflow();
        if ($workflow === null) {
            return null;
        }

        $matches = $this->workflowStepActionRepository->findByWorkflowAndAction($workflow, $action);
        if ($matches === []) {
            throw new \InvalidArgumentException(sprintf(
                'Action "%s" is not allowed in workflow "%s".',
                $action->getKey(),
                $workflow->getName(),
            ));
        }

        if (count($matches) > 1) {
            throw new \InvalidArgumentException(sprintf(
                'Action "%s" is configured multiple times in workflow "%s".',
                $action->getKey(),
                $workflow->getName(),
            ));
        }

        return $matches[0]->getWorkflowStep();
    }

    private function resolveAgentForTask(TicketTask $task): ?Agent
    {
        if ($task->getAssignedAgent() !== null && $task->getAssignedAgent()->isActive()) {
            return $task->getAssignedAgent();
        }

        $agents = $this->availableAgents($task);

        return $agents[0] ?? null;
    }

    /**
     * Returns true when all tasks this task depends on are in Done status.
     */
    public function areDependenciesSatisfied(TicketTask $task): bool
    {
        foreach ($this->ticketTaskDependencyRepository->findByTicketTask($task) as $dependency) {
            if ($dependency->getDependsOn()->getStatus() !== TaskStatus::Done) {
                return false;
            }
        }

        return true;
    }

    /**
     * Advances the ticket to the next workflow step if the current step is automatic and all tasks are done.
     */
    public function advanceTicketStepIfAutomatic(Ticket $ticket): void
    {
        $currentStep = $ticket->getWorkflowStep();
        if ($currentStep === null || $currentStep->getTransitionMode() !== WorkflowStepTransitionMode::Automatic) {
            return;
        }

        foreach ($this->findTasksForWorkflowStep($ticket, $currentStep) as $task) {
            if ($task->getStatus() !== TaskStatus::Done) {
                return;
            }
        }

        $nextStep = $this->workflowStepRepository->findNextByWorkflowStep($currentStep);
        if ($nextStep === null) {
            return;
        }

        $ticket->setWorkflowStep($nextStep);

        $this->entityService->flush();
        $this->dispatchEligibleTasksForCurrentStep($ticket);
    }

    /**
     * @return TicketTask[]
     */
    public function findTasksForWorkflowStep(Ticket $ticket, WorkflowStep $workflowStep): array
    {
        return array_values(array_filter(
            $this->findByTicket($ticket),
            static fn(TicketTask $task): bool => $task->getWorkflowStep()?->getId()->toRfc4122() === $workflowStep->getId()->toRfc4122(),
        ));
    }

    private function buildCreateWithTicketTitle(WorkflowStep $workflowStep, AgentAction $action): string
    {
        $translationKey = self::AGENT_ACTION_TRANSLATION_KEYS[$action->getKey()] ?? null;
        $actionLabel = $translationKey !== null
            ? $this->translator->trans($translationKey, [], 'catalog')
            : $action->getLabel();

        return sprintf('%s · %s', $workflowStep->getName(), $actionLabel);
    }

    private const AGENT_ACTION_TRANSLATION_KEYS = [
        'product.specify'        => 'agent_action.product.specify',
        'tech.plan'              => 'agent_action.tech.plan',
        'design.ui_mockup'       => 'agent_action.design.ui_mockup',
        'dev.backend.implement'  => 'agent_action.dev.backend.implement',
        'dev.frontend.implement' => 'agent_action.dev.frontend.implement',
        'review.code'            => 'agent_action.review.code',
        'qa.validate'            => 'agent_action.qa.validate',
        'docs.write'             => 'agent_action.docs.write',
        'ops.configure'          => 'agent_action.ops.configure',
        'manual.unknown'         => 'agent_action.manual.unknown',
    ];

    private function isAutoExecutableInCurrentStep(TicketTask $task, WorkflowStep $currentStep): bool
    {
        if ($task->getWorkflowStep()?->getId()->toRfc4122() !== $currentStep->getId()->toRfc4122()) {
            return false;
        }

        if (!in_array($task->getStatus(), [TaskStatus::Backlog, TaskStatus::Todo], true)) {
            return false;
        }

        if ($this->hasActiveExecution($task)) {
            return false;
        }

        return $this->areDependenciesSatisfied($task);
    }
}
