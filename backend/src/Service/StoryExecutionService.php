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
use App\Entity\WorkflowStep;
use App\Enum\TaskExecutionTrigger;
use App\Enum\TaskPriority;
use App\Enum\TaskStatus;
use App\Message\AgentTaskMessage;
use App\Repository\AgentRepository;
use App\Repository\TicketTaskRepository;
use App\Repository\WorkflowStepRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

final class StoryExecutionService
{
    private const REWORK_TARGETS = [
        'product_owner_reformulation' => [
            'label' => 'Reformulation Product Owner',
            'description' => 'Rejoue la reformulation métier et la clarification initiale.',
            'workflowStepKey' => 'new',
            'actionKey' => 'product.specify',
        ],
        'lead_tech_planning' => [
            'label' => 'Analyse technique / découpage Lead Tech',
            'description' => 'Regénère le plan technique, les sous-tâches et les dépendances.',
            'workflowStepKey' => 'planning',
            'actionKey' => 'tech.plan',
        ],
    ];

    public function __construct(
        private readonly AgentRepository $agentRepository,
        private readonly TicketTaskRepository $ticketTaskRepository,
        private readonly WorkflowStepRepository $workflowStepRepository,
        private readonly TicketTaskService $ticketTaskService,
        private readonly AgentTaskExecutionService $agentTaskExecutionService,
        private readonly TicketLogService $ticketLogService,
        private readonly MessageBusInterface $bus,
        private readonly RequestCorrelationService $requestCorrelation,
        private readonly LogService $logService,
        private readonly EntityManagerInterface $em,
    ) {}

    public function canExecute(Ticket $ticket): bool
    {
        if (!$ticket->isStory() || $ticket->getWorkflowStep() === null) {
            return false;
        }

        return $this->findExecutableTasksForCurrentStep($ticket) !== [];
    }

    /** @return Agent[] */
    public function availableAgents(Ticket $ticket): array
    {
        if (!$this->canExecute($ticket)) {
            return [];
        }

        $stepTasks = $this->findExecutableTasksForCurrentStep($ticket);
        if ($stepTasks !== []) {
            $agentsById = [];
            foreach ($stepTasks as $task) {
                foreach ($this->ticketTaskService->availableAgents($task) as $availableAgent) {
                    $agentsById[$availableAgent->getId()->toRfc4122()] = $availableAgent;
                }
            }

            return array_values($agentsById);
        }

        return [];
    }

    /**
     * @return array{agent: Agent, skill: string, executionId: string}
     */
    public function execute(Ticket $ticket, ?Agent $agent = null, TaskExecutionTrigger $triggerType = TaskExecutionTrigger::Manual): array
    {
        if (!$this->canExecute($ticket)) {
            throw new \RuntimeException(
                sprintf('No executable task for workflow step "%s".', $ticket->getWorkflowStep()?->getKey() ?? 'null')
            );
        }

        $stepTasks = $this->findExecutableTasksForCurrentStep($ticket);
        $task = $this->resolveTaskForDispatch($stepTasks, $agent);
        $result = $this->ticketTaskService->execute($task, $agent, $triggerType);

        $ticket
            ->setWorkflowStep($task->getWorkflowStep())
            ->setAssignedAgent($result['agent'])
            ->setAssignedRole($task->getAgentAction()->getRole())
            ->setStatus(TaskStatus::InProgress);

        $this->em->flush();

        return $result;
    }

    /**
     * @return array<int, array{
     *   key: string,
     *   label: string,
     *   description: string,
     *   roleSlug: string,
     *   skillSlug: string,
     *   workflowStepKey: string,
     *   availableAgentCount: int,
     *   agent: array{id: string, name: string}|null
     * }>
     */
    public function listReworkTargets(Ticket $ticket): array
    {
        if (!$ticket->isStory()) {
            return [];
        }

        $targets = [];
        foreach (self::REWORK_TARGETS as $key => $target) {
            $config = $this->resolveExecutionConfigForTarget($ticket, $target['workflowStepKey'], $target['actionKey']);
            if ($config === null) {
                continue;
            }

            $roleSlug = $config['action']->getRole()?->getSlug();
            $skillSlug = $config['action']->getSkill()?->getSlug();
            if ($roleSlug === null || $skillSlug === null) {
                continue;
            }

            $agents = $this->findActiveAgentsForRole($ticket, $roleSlug);
            $targets[] = [
                'key' => $key,
                'label' => $target['label'],
                'description' => $target['description'],
                'roleSlug' => $roleSlug,
                'skillSlug' => $skillSlug,
                'workflowStepKey' => $config['workflowStep']->getKey(),
                'availableAgentCount' => count($agents),
                'agent' => isset($agents[0]) ? [
                    'id' => (string) $agents[0]->getId(),
                    'name' => $agents[0]->getName(),
                ] : null,
            ];
        }

        return $targets;
    }

    /**
     * @return array{agent: Agent, skill: string, executionId: string, targetKey: string}
     */
    public function rework(Ticket $ticket, string $targetKey, string $objective, ?string $note = null): array
    {
        if (!$ticket->isStory()) {
            throw new \RuntimeException('Rework is only available for stories and bugs.');
        }

        $target = self::REWORK_TARGETS[$targetKey] ?? null;
        if ($target === null) {
            throw new \RuntimeException(sprintf('Unsupported rework target "%s".', $targetKey));
        }

        $objective = trim($objective);
        $note = $note !== null ? trim($note) : null;
        if ($objective === '') {
            throw new \RuntimeException('A rework objective is required.');
        }

        $config = $this->resolveExecutionConfigForTarget($ticket, $target['workflowStepKey'], $target['actionKey']);
        if ($config === null) {
            throw new \RuntimeException(sprintf('No execution config found for replay target "%s".', $targetKey));
        }

        $roleSlug = $config['action']->getRole()?->getSlug();
        $skillSlug = $config['action']->getSkill()?->getSlug();
        if ($roleSlug === null || $skillSlug === null) {
            throw new \RuntimeException(sprintf(
                'Action "%s" is missing role or skill routing.',
                $config['action']->getKey(),
            ));
        }

        $agent = $this->resolveAgentForRole($ticket, $roleSlug);
        $previousWorkflowStepKey = $ticket->getWorkflowStep()?->getKey();

        $ticket
            ->setWorkflowStep($config['workflowStep'])
            ->setStatus(TaskStatus::InProgress)
            ->setProgress(0)
            ->setAssignedAgent($agent);

        $this->ticketLogService->log(
            ticket: $ticket,
            action: 'rework_requested',
            content: sprintf('Reprise demandée sur "%s". Objectif : %s', $target['label'], $objective),
            metadata: [
                'targetKey' => $targetKey,
                'targetLabel' => $target['label'],
                'targetDescription' => $target['description'],
                'workflowStepKey' => $config['workflowStep']->getKey(),
                'previousWorkflowStepKey' => $previousWorkflowStepKey,
                'objective' => $objective,
                'note' => $note,
                'roleSlug' => $roleSlug,
                'skillSlug' => $skillSlug,
                'agentId' => (string) $agent->getId(),
                'agentName' => $agent->getName(),
            ],
        );

        return array_merge(
            $this->dispatchExecution(
                ticket: $ticket,
                agent: $agent,
                roleSlug: $roleSlug,
                skillSlug: $skillSlug,
                actionKey: $config['action']->getKey(),
                workflowStep: $config['workflowStep'],
                triggerType: TaskExecutionTrigger::Rework,
                taskLogAction: 'execution_rework_dispatched',
                taskLogContent: sprintf('Reprise %s dispatchée vers %s avec le skill %s', $target['label'], $agent->getName(), $skillSlug),
                taskLogMetadata: [
                    'reworkTargetKey' => $targetKey,
                    'reworkObjective' => $objective,
                    'reworkNote' => $note,
                ],
            ),
            ['targetKey' => $targetKey],
        );
    }

    /**
     * @return TicketTask[]
     */
    private function findExecutableTasksForCurrentStep(Ticket $ticket): array
    {
        $currentStep = $ticket->getWorkflowStep();
        if ($currentStep === null) {
            return [];
        }

        $tasks = $this->ticketTaskRepository->findByTicket($ticket);

        return array_values(array_filter($tasks, function (TicketTask $task) use ($currentStep): bool {
            if ($task->getWorkflowStep()?->getId()->toRfc4122() !== $currentStep->getId()->toRfc4122()) {
                return false;
            }

            if ($task->getStatus() === TaskStatus::Done) {
                return false;
            }

            if ($this->ticketTaskService->hasActiveExecution($task)) {
                return false;
            }

            return $this->ticketTaskService->areDependenciesSatisfied($task);
        }));
    }

    /**
     * @param TicketTask[] $tasks
     */
    private function resolveTaskForDispatch(array $tasks, ?Agent $agent): TicketTask
    {
        if ($agent === null) {
            return $tasks[0];
        }

        foreach ($tasks as $task) {
            foreach ($this->ticketTaskService->availableAgents($task) as $availableAgent) {
                if ($availableAgent->getId()->toRfc4122() === $agent->getId()->toRfc4122()) {
                    return $task;
                }
            }
        }

        throw new \RuntimeException(sprintf('Selected agent "%s" cannot execute any ready task for the current step.', $agent->getName()));
    }

    /** @return Agent[] */
    private function findActiveAgentsForRole(Ticket $ticket, string $roleSlug): array
    {
        $team = $ticket->getProject()->getTeam();

        if ($team !== null) {
            return $this->agentRepository->findActiveByRoleSlugAndTeam($roleSlug, $team);
        }

        return $this->agentRepository->findActiveByRoleSlug($roleSlug);
    }

    private function resolveAgentForRole(Ticket $ticket, string $roleSlug): Agent
    {
        $agents = $this->findActiveAgentsForRole($ticket, $roleSlug);
        $team = $ticket->getProject()->getTeam();
        if ($agents === []) {
            throw new \RuntimeException(
                sprintf(
                    'No active agent found with role "%s"%s.',
                    $roleSlug,
                    $team !== null ? ' in team "' . $team->getName() . '"' : '',
                )
            );
        }

        return $agents[0];
    }

    private function resolveExecutionConfigForTarget(Ticket $ticket, string $workflowStepKey, string $actionKey): ?array
    {
        $workflow = $ticket->getProject()->getWorkflow();
        if ($workflow === null) {
            return null;
        }

        $workflowStep = $this->workflowStepRepository->findByWorkflowAndKey($workflow, $workflowStepKey);
        if ($workflowStep === null) {
            return null;
        }

        foreach ($workflowStep->getActions() as $stepAction) {
            if ($stepAction->getAgentAction()->getKey() === $actionKey) {
                return [
                    'workflowStep' => $workflowStep,
                    'action' => $stepAction->getAgentAction(),
                ];
            }
        }

        return null;
    }

    /**
     * @return array{agent: Agent, skill: string, executionId: string}
     */
    private function dispatchExecution(
        Ticket $ticket,
        Agent $agent,
        string $roleSlug,
        string $skillSlug,
        string $actionKey,
        ?WorkflowStep $workflowStep,
        TaskExecutionTrigger $triggerType,
        string $taskLogAction,
        string $taskLogContent,
        array $taskLogMetadata = [],
    ): array {
        $action = $workflowStep?->getActions()
            ->filter(static fn($stepAction) => $stepAction->getAgentAction()->getKey() === $actionKey)
            ->first()?->getAgentAction();
        if (!$action instanceof AgentAction) {
            throw new \RuntimeException(sprintf('Unknown agent action "%s".', $actionKey));
        }

        $ticket
            ->setAssignedAgent($agent)
            ->setAssignedRole($action->getRole())
            ->setStatus(TaskStatus::InProgress);
        $ticket->setWorkflowStep($workflowStep);

        $ticketTask = $this->ticketTaskRepository->findOneLatestByTicketAndWorkflowStepAndAction($ticket, $workflowStep, $action);
        if ($ticketTask === null) {
            $ticketTask = $this->ticketTaskService->create(
                ticket: $ticket,
                actionKey: $action->getKey(),
                title: $this->buildStageTaskTitle($ticket, $workflowStep, $action),
                description: $ticket->getDescription(),
                priority: $ticket->getPriority(),
            );
        }

        $ticketTask
            ->setWorkflowStep($workflowStep)
            ->setAssignedAgent($agent)
            ->setAssignedRole($action->getRole())
            ->setStatus(TaskStatus::InProgress)
            ->setProgress(0);

        $requestRef = $this->requestCorrelation->getCurrentRequestRef();
        $traceRef = Uuid::v7()->toRfc4122();
        $execution = $this->agentTaskExecutionService->createExecution(
            ticketTask: $ticketTask,
            requestedAgent: $agent,
            triggerType: $triggerType,
            requestRef: $requestRef,
            traceRef: $traceRef,
        );

        $this->ticketLogService->log(
            ticket: $ticket,
            action: $taskLogAction,
            content: $taskLogContent,
            ticketTask: $ticketTask,
            metadata: [
                'agentId' => (string) $agent->getId(),
                'agentName' => $agent->getName(),
                'skillSlug' => $skillSlug,
                'roleSlug' => $roleSlug,
                'actionKey' => $action->getKey(),
                'executionId' => (string) $execution->getId(),
                ...$taskLogMetadata,
            ],
        );

        $this->bus->dispatch(new AgentTaskMessage(
            ticketTaskId: (string) $ticketTask->getId(),
            agentId: (string) $agent->getId(),
            skillSlug: $skillSlug,
            agentTaskExecutionId: (string) $execution->getId(),
            requestRef: $requestRef,
            traceRef: $traceRef,
        ));

        $this->logService->record(
            source: 'backend',
            category: 'runtime',
            level: 'info',
            title: '',
            message: '',
            options: [
                'title_i18n' => [
                    'domain' => 'logs',
                    'key' => $triggerType === TaskExecutionTrigger::Rework
                        ? 'logs.backend.runtime.task_rework_dispatched.title'
                        : 'logs.backend.runtime.task_dispatched.title',
                ],
                'message_i18n' => [
                    'domain' => 'logs',
                    'key' => $triggerType === TaskExecutionTrigger::Rework
                        ? 'logs.backend.runtime.task_rework_dispatched.message'
                        : 'logs.backend.runtime.task_dispatched.message',
                    'parameters' => [
                        '%taskTitle%' => $ticket->getTitle(),
                        '%agentName%' => $agent->getName(),
                        '%skillSlug%' => $skillSlug,
                    ],
                ],
                'project_id' => (string) $ticket->getProject()->getId(),
                'task_id' => (string) $ticketTask->getId(),
                'agent_id' => (string) $agent->getId(),
                'request_ref' => $requestRef,
                'trace_ref' => $traceRef,
                'context' => [
                    'task_execution_id' => (string) $execution->getId(),
                    'workflow_step' => $ticket->getWorkflowStep()?->getKey(),
                    'skill_slug' => $skillSlug,
                    'role_slug' => $roleSlug,
                    'trigger_type' => $triggerType->value,
                    'ticket_id' => (string) $ticket->getId(),
                    ...$taskLogMetadata,
                ],
            ],
        );

        $this->em->flush();

        return ['agent' => $agent, 'skill' => $skillSlug, 'executionId' => (string) $execution->getId()];
    }

    private function buildStageTaskTitle(Ticket $ticket, ?WorkflowStep $workflowStep, AgentAction $action): string
    {
        $prefix = $workflowStep?->getName() ?? $action->getLabel();

        return sprintf('%s · %s', $prefix, $ticket->getTitle());
    }
}
