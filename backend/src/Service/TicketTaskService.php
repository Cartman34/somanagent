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
use App\Enum\DispatchMode;
use App\Enum\TaskExecutionTrigger;
use App\Enum\TaskPriority;
use App\Enum\TaskStatus;
use App\Enum\WorkflowStepTransitionMode;
use App\Message\AgentTaskMessage;
use App\Repository\AgentActionRepository;
use App\Repository\AgentRepository;
use App\Repository\AgentTaskExecutionRepository;
use App\Repository\AuditLogRepository;
use App\Repository\TicketTaskDependencyRepository;
use App\Repository\TicketTaskRepository;
use App\Repository\WorkflowStepActionRepository;
use App\Repository\WorkflowStepRepository;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Manages ticket tasks: creation, dependency resolution, agent dispatch, and execution lifecycle.
 */
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
        private readonly AuditLogRepository $auditLogRepository,
        private readonly AgentActionRepository $agentActionRepository,
        private readonly AgentRepository $agentRepository,
        private readonly WorkflowStepActionRepository $workflowStepActionRepository,
        private readonly WorkflowStepRepository $workflowStepRepository,
        private readonly AgentTaskExecutionService $agentTaskExecutionService,
        private readonly TicketLogService $ticketLogService,
        private readonly RealtimeUpdateService $realtimeUpdateService,
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
        $this->realtimeUpdateService->publishTaskChanged($task, 'created');

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
        $this->realtimeUpdateService->publishTaskChanged($task, 'updated');

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

        if ($task->getStatus() === TaskStatus::AwaitingDispatch) {
            $currentStep = $task->getTicket()->getWorkflowStep();
            if ($currentStep === null || !$this->isDispatchEligibleInCurrentStep($task, $currentStep)) {
                throw new \LogicException('This task is no longer eligible for dispatch in the current workflow step.');
            }
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
        $previousStatus = $task->getStatus();

        $task
            ->setAssignedRole($task->getAgentAction()->getRole())
            ->setAssignedAgent($resolvedAgent)
            ->setStatus(TaskStatus::InProgress);

        if ($previousStatus !== TaskStatus::Done) {
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
        $this->realtimeUpdateService->publishTaskChanged($task, 'dispatched', [
            'status' => $task->getStatus()->value,
        ]);

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
        if (!$this->canResume($task)) {
            throw new \LogicException('This task cannot be resumed because it has never been executed or completed before.');
        }

        return $this->execute($task, $agent, TaskExecutionTrigger::Manual);
    }

    /**
     * Returns whether this task already has enough history to be replayed safely.
     */
    public function canResume(TicketTask $task): bool
    {
        if ($task->getStatus() === TaskStatus::Done) {
            return true;
        }

        if ($this->agentTaskExecutionRepository->hasAnyByTicketTask($task)) {
            return true;
        }

        return $this->auditLogRepository->hasTaskCompletionHistory($task);
    }

    /**
     * Returns the next workflow step after the given one, if any.
     */
    public function findNextWorkflowStep(WorkflowStep $workflowStep): ?WorkflowStep
    {
        return $this->workflowStepRepository->findNextByWorkflowStep($workflowStep);
    }

    /**
     * Describes the execution scope attached to the current task state.
     *
     * @return array{
     *   task_actions: array<int, array<string, mixed>>,
     *   ticket_transitions: array<int, array<string, mixed>>,
     *   allowed_effects: string[]
     * }
     */
    public function describeExecutionScope(TicketTask $task): array
    {
        $taskActions = [[
            'type' => 'execute_current_action',
            'action_key' => $task->getAgentAction()->getKey(),
            'action_label' => $task->getAgentAction()->getLabel(),
        ]];

        if ($this->canResume($task)) {
            $taskActions[] = [
                'type' => 'resume_current_action',
                'action_key' => $task->getAgentAction()->getKey(),
                'action_label' => $task->getAgentAction()->getLabel(),
            ];
        }

        $ticketTransitions = [];
        $currentStep = $task->getTicket()->getWorkflowStep();
        if ($currentStep !== null && $currentStep->getTransitionMode()->value === 'manual') {
            $nextStep = $this->findNextWorkflowStep($currentStep);
            if ($nextStep !== null) {
                $ticketTransitions[] = [
                    'type' => 'advance_workflow_step',
                    'from_step' => [
                        'key' => $currentStep->getKey(),
                        'name' => $currentStep->getName(),
                    ],
                    'to_step' => [
                        'key' => $nextStep->getKey(),
                        'name' => $nextStep->getName(),
                    ],
                ];
            }
        }

        return [
            'task_actions' => $taskActions,
            'ticket_transitions' => $ticketTransitions,
            'allowed_effects' => $this->buildAllowedEffectsForTask($task),
        ];
    }

    /**
     * Persists the runtime resource snapshot captured for one execution attempt.
     *
     * @param array<string, mixed> $resourceSnapshot
     */
    public function captureExecutionResourceSnapshot(\App\Entity\AgentTaskExecutionAttempt $attempt, array $resourceSnapshot): void
    {
        $this->agentTaskExecutionService->captureResourceSnapshot($attempt, $resourceSnapshot);
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
     * Returns the backend effects that are allowed for one task execution.
     *
     * @return string[]
     */
    private function buildAllowedEffectsForTask(TicketTask $task): array
    {
        $effects = [
            'log_agent_response',
            'ask_clarification',
            'complete_current_task',
        ];

        // TODO: allowed effects per action should be stored on AgentAction in the database,
        //       so that adding or changing an action type requires no code change here.
        return match ($task->getAgentAction()->getKey()) {
            'product.specify' => [
                'log_agent_response',
                'complete_current_task',
                'rewrite_ticket',
                'complete_ticket',
            ],
            'tech.plan' => [
                ...$effects,
                'replace_planning_tasks',
                'create_subtasks',
                'prepare_branch',
                'update_ticket_progress',
            ],
            default => $effects,
        };
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

        $dispatchMode = $ticket->getProject()->getDispatchMode();
        $dispatched = 0;
        $requiresFlush = false;
        foreach ($this->findTasksForWorkflowStep($ticket, $currentStep) as $task) {
            if (!$this->isDispatchEligibleInCurrentStep($task, $currentStep)) {
                continue;
            }

            if ($dispatchMode === DispatchMode::Manual) {
                if ($this->markAwaitingDispatch($task)) {
                    ++$dispatched;
                    $requiresFlush = true;
                }
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

        if ($requiresFlush) {
            $this->entityService->flush();
            foreach ($this->findTasksForWorkflowStep($ticket, $currentStep) as $task) {
                if ($task->getStatus() !== TaskStatus::AwaitingDispatch) {
                    continue;
                }

                $this->realtimeUpdateService->publishTaskChanged($task, 'awaiting_dispatch', [
                    'status' => $task->getStatus()->value,
                ]);
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
        $this->realtimeUpdateService->publishTaskChanged($task, 'execution_failed', [
            'status' => $task->getStatus()->value,
        ]);

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
        $this->realtimeUpdateService->publishTaskChanged($task, 'status_changed', [
            'status' => $status->value,
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
        $this->realtimeUpdateService->publishTaskChanged($task, 'progress_updated', [
            'progress' => $progress,
        ]);

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
        $this->realtimeUpdateService->publishTaskChanged($task, 'priority_changed', [
            'priority' => $priority->value,
        ]);

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
        $project = $task->getTicket()->getProject();
        $ticketId = (string) $task->getTicket()->getId();
        $taskId = (string) $task->getId();
        $this->entityService->delete($task, AuditAction::TaskDeleted);
        $this->realtimeUpdateService->publishTaskDeleted($project, $ticketId, $taskId);
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
        $this->realtimeUpdateService->publishTaskChanged($task, 'seed_created');

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

        if (!$this->describeWorkflowStepProgress($ticket)['canAdvance']) {
            return;
        }

        $nextStep = $this->workflowStepRepository->findNextByWorkflowStep($currentStep);
        if ($nextStep === null) {
            return;
        }

        $ticket->setWorkflowStep($nextStep);

        $this->entityService->flush();
        $this->realtimeUpdateService->publishTicketChanged($ticket, 'workflow_step_changed', [
            'workflowStepKey' => $nextStep->getKey(),
        ]);
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

    /**
     * Describes whether the current workflow step can advance and why.
     *
     * @return array{
     *   canAdvance: bool,
     *   status: 'ready'|'ready_with_warnings'|'blocked_pending_blocking_answers'|'blocked_active_execution'|'blocked_incomplete_tasks'|'unavailable',
     *   pendingAnswerCount: int,
     *   pendingBlockingAnswerCount: int
     * }
     */
    public function describeWorkflowStepProgress(Ticket $ticket): array
    {
        $currentStep = $ticket->getWorkflowStep();
        if ($currentStep === null) {
            return [
                'canAdvance' => false,
                'status' => 'unavailable',
                'pendingAnswerCount' => 0,
                'pendingBlockingAnswerCount' => 0,
            ];
        }

        $pendingAnswerCount = 0;
        $pendingBlockingAnswerCount = 0;
        $hasActiveExecution = false;
        $hasIncompleteTask = false;

        foreach ($this->findTasksForWorkflowStep($ticket, $currentStep) as $task) {
            $taskPendingAnswerCount = $this->ticketLogService->countPendingAnswersForTask($task);
            $taskPendingBlockingAnswerCount = $this->ticketLogService->countPendingBlockingAnswersForTask($task);
            $pendingAnswerCount += $taskPendingAnswerCount;
            $pendingBlockingAnswerCount += $taskPendingBlockingAnswerCount;

            if ($this->hasActiveExecution($task)) {
                $hasActiveExecution = true;
                continue;
            }

            if ($taskPendingBlockingAnswerCount > 0) {
                continue;
            }

            if ($task->getStatus()->isDone()) {
                continue;
            }

            $hasIncompleteTask = true;
        }

        if ($hasActiveExecution) {
            return [
                'canAdvance' => false,
                'status' => 'blocked_active_execution',
                'pendingAnswerCount' => $pendingAnswerCount,
                'pendingBlockingAnswerCount' => $pendingBlockingAnswerCount,
            ];
        }

        if ($pendingBlockingAnswerCount > 0) {
            return [
                'canAdvance' => false,
                'status' => 'blocked_pending_blocking_answers',
                'pendingAnswerCount' => $pendingAnswerCount,
                'pendingBlockingAnswerCount' => $pendingBlockingAnswerCount,
            ];
        }

        if ($hasIncompleteTask) {
            return [
                'canAdvance' => false,
                'status' => 'blocked_incomplete_tasks',
                'pendingAnswerCount' => $pendingAnswerCount,
                'pendingBlockingAnswerCount' => $pendingBlockingAnswerCount,
            ];
        }

        return [
            'canAdvance' => true,
            'status' => $pendingAnswerCount > 0 ? 'ready_with_warnings' : 'ready',
            'pendingAnswerCount' => $pendingAnswerCount,
            'pendingBlockingAnswerCount' => $pendingBlockingAnswerCount,
        ];
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

    private function isDispatchEligibleInCurrentStep(TicketTask $task, WorkflowStep $currentStep): bool
    {
        if ($task->getWorkflowStep()?->getId()->toRfc4122() !== $currentStep->getId()->toRfc4122()) {
            return false;
        }

        if (!in_array($task->getStatus(), [TaskStatus::Backlog, TaskStatus::Todo, TaskStatus::AwaitingDispatch], true)) {
            return false;
        }

        if ($this->hasActiveExecution($task)) {
            return false;
        }

        return $this->areDependenciesSatisfied($task);
    }

    /**
     * Marks one eligible task as awaiting explicit dispatch authorization.
     */
    private function markAwaitingDispatch(TicketTask $task): bool
    {
        if ($task->getStatus() === TaskStatus::AwaitingDispatch) {
            return false;
        }

        $task->setStatus(TaskStatus::AwaitingDispatch);
        $this->ticketLogService->log(
            ticket: $task->getTicket(),
            action: 'execution_pending',
            content: 'Task is awaiting explicit dispatch authorization.',
            ticketTask: $task,
            metadata: [
                'actionKey' => $task->getAgentAction()->getKey(),
                'roleSlug' => $task->getAgentAction()->getRole()?->getSlug(),
                'skillSlug' => $task->getAgentAction()->getSkill()?->getSlug(),
            ],
        );

        return true;
    }
}
