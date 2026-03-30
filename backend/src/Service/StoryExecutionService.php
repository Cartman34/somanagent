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
use App\Enum\StoryStatus;
use App\Enum\TaskExecutionTrigger;
use App\Enum\TaskPriority;
use App\Enum\TaskStatus;
use App\Message\AgentTaskMessage;
use App\Repository\AgentActionRepository;
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
            'configStatus' => StoryStatus::New,
            'targetStoryStatus' => StoryStatus::New,
        ],
        'lead_tech_planning' => [
            'label' => 'Analyse technique / découpage Lead Tech',
            'description' => 'Regénère le plan technique, les sous-tâches et les dépendances.',
            'configStatus' => StoryStatus::Approved,
            'targetStoryStatus' => StoryStatus::Planning,
        ],
    ];

    public function __construct(
        private readonly AgentRepository $agentRepository,
        private readonly AgentActionRepository $agentActionRepository,
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
        if (!$ticket->isStory() || $ticket->getStoryStatus() === null) {
            return false;
        }

        return $this->resolveExecutionConfigForStatus($ticket, $ticket->getStoryStatus()) !== null;
    }

    /** @return Agent[] */
    public function availableAgents(Ticket $ticket): array
    {
        if (!$this->canExecute($ticket)) {
            return [];
        }

        ['role' => $roleSlug] = $this->resolveExecutionConfig($ticket);

        return $this->findActiveAgentsForRole($ticket, $roleSlug);
    }

    /**
     * @return array{agent: Agent, skill: string, executionId: string}
     */
    public function execute(Ticket $ticket, ?Agent $agent = null, TaskExecutionTrigger $triggerType = TaskExecutionTrigger::Manual): array
    {
        if (!$this->canExecute($ticket)) {
            throw new \RuntimeException(
                sprintf('No execution config for storyStatus "%s".', $ticket->getStoryStatus()?->value ?? 'null')
            );
        }

        $config = $this->resolveExecutionConfig($ticket);
        $resolvedAgent = $agent ?? $this->resolveAgentForRole($ticket, $config['role']);

        return $this->dispatchExecution(
            ticket: $ticket,
            agent: $resolvedAgent,
            roleSlug: $config['role'],
            skillSlug: $config['skill'],
            actionKey: $config['actionKey'],
            workflowStep: $config['workflowStep'],
            triggerType: $triggerType,
            storyStatusForExecution: $config['transition'],
            taskLogAction: 'execution_dispatched',
            taskLogContent: sprintf('Agent %s dispatché avec le skill %s', $resolvedAgent->getName(), $config['skill']),
        );
    }

    /**
     * @return array<int, array{
     *   key: string,
     *   label: string,
     *   description: string,
     *   roleSlug: string,
     *   skillSlug: string,
     *   workflowStepKey: string,
     *   targetStoryStatus: string,
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
            $config = $this->resolveExecutionConfigForStatus($ticket, $target['configStatus']);
            if ($config === null) {
                continue;
            }

            $agents = $this->findActiveAgentsForRole($ticket, $config['role']);
            $targets[] = [
                'key' => $key,
                'label' => $target['label'],
                'description' => $target['description'],
                'roleSlug' => $config['role'],
                'skillSlug' => $config['skill'],
                'workflowStepKey' => $config['workflowStep']->getKey(),
                'targetStoryStatus' => $target['targetStoryStatus']->value,
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

        $config = $this->resolveExecutionConfigForStatus($ticket, $target['configStatus']);
        if ($config === null) {
            throw new \RuntimeException(sprintf('No execution config found for replay target "%s".', $targetKey));
        }

        $agent = $this->resolveAgentForRole($ticket, $config['role']);
        $previousStatus = $ticket->getStoryStatus()?->value;
        $targetStoryStatus = $target['targetStoryStatus'];

        $ticket
            ->setStoryStatus($targetStoryStatus)
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
                'workflowStepKey' => $target['configStatus']->value,
                'targetStoryStatus' => $targetStoryStatus->value,
                'previousStoryStatus' => $previousStatus,
                'objective' => $objective,
                'note' => $note,
                'roleSlug' => $config['role'],
                'skillSlug' => $config['skill'],
                'agentId' => (string) $agent->getId(),
                'agentName' => $agent->getName(),
            ],
        );

        return array_merge(
            $this->dispatchExecution(
                ticket: $ticket,
                agent: $agent,
                roleSlug: $config['role'],
                skillSlug: $config['skill'],
                actionKey: $config['actionKey'],
                workflowStep: $config['workflowStep'],
                triggerType: TaskExecutionTrigger::Rework,
                storyStatusForExecution: $targetStoryStatus,
                taskLogAction: 'execution_rework_dispatched',
                taskLogContent: sprintf('Reprise %s dispatchée vers %s avec le skill %s', $target['label'], $agent->getName(), $config['skill']),
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
     * @return array{workflowStep: WorkflowStep, role: string, skill: string, actionKey: string, transition: StoryStatus}
     */
    public function resolveExecutionConfig(Ticket $ticket): array
    {
        $storyStatus = $ticket->getStoryStatus();
        $config = $storyStatus !== null ? $this->resolveExecutionConfigForStatus($ticket, $storyStatus) : null;

        if ($config === null || $storyStatus === null) {
            throw new \RuntimeException(
                sprintf('No execution config for storyStatus "%s".', $storyStatus?->value ?? 'null')
            );
        }

        return $config;
    }

    /**
     * @return array{workflowStep: WorkflowStep, role: string, skill: string, actionKey: string, transition: StoryStatus}|null
     */
    public function resolveExecutionConfigForStatus(Ticket $ticket, StoryStatus $storyStatus): ?array
    {
        $step = $this->resolveWorkflowStepForStatus($ticket, $storyStatus);
        if ($step === null || $step->getRoleSlug() === null || $step->getSkillSlug() === null) {
            return null;
        }

        $action = $this->agentActionRepository->findOneActiveByRoleAndSkill($step->getRoleSlug(), $step->getSkillSlug())
            ?? $this->agentActionRepository->findOneActiveByRoleSlug($step->getRoleSlug());
        if ($action === null) {
            return null;
        }

        return [
            'workflowStep' => $step,
            'role' => $step->getRoleSlug(),
            'skill' => $step->getSkillSlug(),
            'actionKey' => $action->getKey(),
            'transition' => $this->resolveDispatchedStoryStatus($storyStatus, $step),
        ];
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

    private function resolveWorkflowStepForStatus(Ticket $ticket, ?StoryStatus $storyStatus): ?WorkflowStep
    {
        if ($storyStatus === null) {
            return null;
        }

        $team = $ticket->getProject()->getTeam();
        if ($team === null) {
            return null;
        }

        return $this->workflowStepRepository->findByTeamAndStoryStatus($team, $storyStatus);
    }

    private function resolveDispatchedStoryStatus(StoryStatus $currentStatus, WorkflowStep $workflowStep): StoryStatus
    {
        $targetStatus = StoryStatus::tryFrom($workflowStep->getKey());
        if ($targetStatus === null) {
            return $currentStatus;
        }

        if ($currentStatus === $targetStatus || $currentStatus->canTransitionTo($targetStatus)) {
            return $targetStatus;
        }

        return $currentStatus;
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
        ?StoryStatus $storyStatusForExecution,
        string $taskLogAction,
        string $taskLogContent,
        array $taskLogMetadata = [],
    ): array {
        $action = $this->agentActionRepository->findOneByKey($actionKey);
        if ($action === null) {
            throw new \RuntimeException(sprintf('Unknown agent action "%s".', $actionKey));
        }

        $ticket
            ->setAssignedAgent($agent)
            ->setAssignedRole($action->getRole())
            ->setStatus(TaskStatus::InProgress);

        if ($storyStatusForExecution !== null) {
            $ticket->setStoryStatus($storyStatusForExecution);
        }
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
                    'story_status' => $ticket->getStoryStatus()?->value,
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
