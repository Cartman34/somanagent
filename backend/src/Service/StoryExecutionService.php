<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Agent;
use App\Entity\Task;
use App\Entity\TaskLog;
use App\Enum\TaskExecutionTrigger;
use App\Enum\StoryStatus;
use App\Enum\TaskStatus;
use App\Message\AgentTaskMessage;
use App\Repository\AgentRepository;
use App\Repository\WorkflowStepRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Orchestrates the agent execution for a user story or bug.
 *
 * Maps the current storyStatus to the appropriate agent role and skill via two strategies,
 * applied in priority order:
 *
 *  1. **Workflow-driven (F3):** if the story's project has a team with a workflow containing
 *     a step whose `storyStatusTrigger` matches the current storyStatus, that step's
 *     roleSlug and skillSlug are used, and the agent search is scoped to the team (F2).
 *
 *  2. **Hardcoded fallback (backwards-compatible):** if no workflow step is found, the
 *     built-in EXECUTION_MAP is used with a global agent search.
 *
 * Supported statuses:
 *  - approved      → lead-tech  / tech-planning   → transitions to planning
 *  - graphic_design → ui-ux-designer / ui-design  → stays (agent completes after)
 *  - development   → php-dev   / php-backend-dev  → stays (agent completes after)
 *  - code_review   → lead-tech  / code-reviewer   → stays (agent completes after)
 */
final class StoryExecutionService
{
    /**
     * Replayable ticket stages exposed in the UI before the generic workflow rework model lands.
     *
     * @var array<string, array{
     *   label: string,
     *   description: string,
     *   configStatus: StoryStatus,
     *   targetStoryStatus: StoryStatus
     * }>
     */
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

    /**
     * Fallback mapping: storyStatus.value → [roleSlug, skillSlug, ?StoryStatus to transition to].
     * Used when no matching workflow step is found for the project's team.
     *
     * @var array<string, array{role: string, skill: string, transition: StoryStatus|null}>
     */
    private const EXECUTION_MAP = [
        'new'            => ['role' => 'product-owner',  'skill' => 'product-owner',   'transition' => null],
        'approved'       => ['role' => 'lead-tech',      'skill' => 'tech-planning',   'transition' => StoryStatus::Planning],
        'graphic_design' => ['role' => 'ui-ux-designer', 'skill' => 'ui-design',       'transition' => null],
        'development'    => ['role' => 'php-dev',         'skill' => 'php-backend-dev', 'transition' => null],
        'code_review'    => ['role' => 'lead-tech',       'skill' => 'code-reviewer',   'transition' => null],
    ];

    public function __construct(
        private readonly AgentRepository        $agentRepository,
        private readonly WorkflowStepRepository $workflowStepRepository,
        private readonly MessageBusInterface    $bus,
        private readonly RequestCorrelationService $requestCorrelation,
        private readonly LogService             $logService,
        private readonly TaskExecutionService   $taskExecutionService,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Checks whether this story's current status has an automated execution defined
     * (either via a workflow step or the hardcoded fallback map).
     *
     * @param Task $story The story or bug to check
     * @return bool       True if automated execution is available
     */
    public function canExecute(Task $story): bool
    {
        if (!$story->isStory() || $story->getStoryStatus() === null) {
            return false;
        }

        $storyStatus = $story->getStoryStatus();

        // Check workflow-driven config first (F3)
        $team = $story->getProject()->getTeam();
        if ($team !== null) {
            $step = $this->workflowStepRepository->findByTeamAndStoryStatus($team, $storyStatus);
            if ($step !== null && $step->getRoleSlug() !== null && $step->getSkillSlug() !== null) {
                return true;
            }
        }

        // Fall back to hardcoded map
        return isset(self::EXECUTION_MAP[$storyStatus->value]);
    }

    /**
     * Returns the available agents for this story's current status.
     * Agents are scoped to the project's team when one is assigned (F2), otherwise global.
     * Returns an empty array if no execution config exists for the current status.
     *
     * @param Task $story The story or bug
     * @return Agent[]    Available agents for execution
     */
    public function availableAgents(Task $story): array
    {
        if (!$this->canExecute($story)) {
            return [];
        }

        ['role' => $roleSlug] = $this->resolveExecutionConfig($story);

        return $this->findActiveAgentsForRole($story, $roleSlug);
    }

    /**
     * Executes the story using the given agent (or the first available agent if null).
     *
     * - Validates execution is possible for the current storyStatus
     * - Optionally transitions the story status before dispatching
     * - Dispatches an AgentTaskMessage to the async transport
     *
     * @param Task       $story The story or bug to execute
     * @param Agent|null $agent Specific agent to use, or null to auto-select
     * @throws \RuntimeException if no execution config or no available agent
     * @throws \LogicException   if the story status transition is not allowed
     *
     * @return array{agent: Agent, skill: string, executionId: string}
     */
    public function execute(Task $story, ?Agent $agent = null, TaskExecutionTrigger $triggerType = TaskExecutionTrigger::Manual): array
    {
        if (!$this->canExecute($story)) {
            throw new \RuntimeException(
                sprintf('No execution config for storyStatus "%s".', $story->getStoryStatus()?->value ?? 'null')
            );
        }

        $config = $this->resolveExecutionConfig($story);
        $resolvedAgent = $agent ?? $this->resolveAgentForRole($story, $config['role']);

        return $this->dispatchExecution(
            story: $story,
            agent: $resolvedAgent,
            roleSlug: $config['role'],
            skillSlug: $config['skill'],
            workflowStepKey: $story->getStoryStatus()?->value,
            triggerType: $triggerType,
            storyStatusForExecution: $config['transition'] ?? $story->getStoryStatus(),
            taskLogAction: 'execution_dispatched',
            taskLogContent: sprintf('Agent %s dispatché avec le skill %s', $resolvedAgent->getName(), $config['skill']),
        );
    }

    /**
     * Returns the replayable agent stages currently supported from the ticket UI.
     *
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
    public function listReworkTargets(Task $story): array
    {
        if (!$story->isStory()) {
            return [];
        }

        $targets = [];
        foreach (self::REWORK_TARGETS as $key => $target) {
            $config = $this->resolveExecutionConfigForStatus($story, $target['configStatus']);
            if ($config === null) {
                continue;
            }

            $agents = $this->findActiveAgentsForRole($story, $config['role']);
            $targets[] = [
                'key' => $key,
                'label' => $target['label'],
                'description' => $target['description'],
                'roleSlug' => $config['role'],
                'skillSlug' => $config['skill'],
                'workflowStepKey' => $target['configStatus']->value,
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
     * Re-dispatches the story to a supported replayable agent stage and records why it was requested.
     *
     * @return array{agent: Agent, skill: string, executionId: string, targetKey: string}
     */
    public function rework(Task $story, string $targetKey, string $objective, ?string $note = null): array
    {
        if (!$story->isStory()) {
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

        $config = $this->resolveExecutionConfigForStatus($story, $target['configStatus']);
        if ($config === null) {
            throw new \RuntimeException(sprintf('No execution config found for replay target "%s".', $targetKey));
        }

        $agent = $this->resolveAgentForRole($story, $config['role']);
        $previousStatus = $story->getStoryStatus()?->value;
        $targetStoryStatus = $target['targetStoryStatus'];

        $story
            ->setStoryStatus($targetStoryStatus)
            ->setStatus(TaskStatus::InProgress)
            ->setProgress(0)
            ->setAssignedAgent($agent);

        // Stored in DB for the in-app log UI, so the human-facing message stays in French.
        $reworkLog = (new TaskLog(
            $story,
            'rework_requested',
            sprintf('Reprise demandée sur "%s". Objectif : %s', $target['label'], $objective),
        ))->setMetadata([
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
        ]);
        $this->em->persist($reworkLog);

        return array_merge(
            $this->dispatchExecution(
                story: $story,
                agent: $agent,
                roleSlug: $config['role'],
                skillSlug: $config['skill'],
                workflowStepKey: $target['configStatus']->value,
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
     * Resolves the execution configuration (roleSlug, skillSlug, transition) for the story's
     * current status. Tries workflow-driven config first (F3), falls back to EXECUTION_MAP.
     *
     * @param Task $story The story whose storyStatus drives the lookup
     * @return array{role: string, skill: string, transition: StoryStatus|null}
     * @throws \RuntimeException if no config is found (should be gated by canExecute())
     */
    public function resolveExecutionConfig(Task $story): array
    {
        $storyStatus = $story->getStoryStatus();
        $config = $storyStatus !== null ? $this->resolveExecutionConfigForStatus($story, $storyStatus) : null;

        if ($config === null || $storyStatus === null) {
            throw new \RuntimeException(
                sprintf('No execution config for storyStatus "%s".', $storyStatus?->value ?? 'null')
            );
        }

        return $config;
    }

    /**
     * Resolves the execution config for a specific story status without requiring the task to currently be in that state.
     *
     * @return array{role: string, skill: string, transition: StoryStatus|null}|null
     */
    public function resolveExecutionConfigForStatus(Task $story, StoryStatus $storyStatus): ?array
    {
        $team = $story->getProject()->getTeam();

        if ($team !== null) {
            $step = $this->workflowStepRepository->findByTeamAndStoryStatus($team, $storyStatus);
            if ($step !== null && $step->getRoleSlug() !== null && $step->getSkillSlug() !== null) {
                return [
                    'role' => $step->getRoleSlug(),
                    'skill' => $step->getSkillSlug(),
                    'transition' => self::EXECUTION_MAP[$storyStatus->value]['transition'] ?? null,
                ];
            }
        }

        if (!isset(self::EXECUTION_MAP[$storyStatus->value])) {
            return null;
        }

        return self::EXECUTION_MAP[$storyStatus->value];
    }

    /**
     * Returns active agents that can execute the given role for this story's team context.
     *
     * @return Agent[]
     */
    private function findActiveAgentsForRole(Task $story, string $roleSlug): array
    {
        $team = $story->getProject()->getTeam();

        if ($team !== null) {
            return $this->agentRepository->findActiveByRoleSlugAndTeam($roleSlug, $team);
        }

        return $this->agentRepository->findActiveByRoleSlug($roleSlug);
    }

    /**
     * Picks the first active agent matching the required role, scoped to the story's team when relevant.
     */
    private function resolveAgentForRole(Task $story, string $roleSlug): Agent
    {
        $agents = $this->findActiveAgentsForRole($story, $roleSlug);
        $team = $story->getProject()->getTeam();
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

    /**
     * Centralizes async story dispatch so manual execution and targeted rework share the same tracking behavior.
     *
     * @return array{agent: Agent, skill: string, executionId: string}
     */
    private function dispatchExecution(
        Task $story,
        Agent $agent,
        string $roleSlug,
        string $skillSlug,
        ?string $workflowStepKey,
        TaskExecutionTrigger $triggerType,
        ?StoryStatus $storyStatusForExecution,
        string $taskLogAction,
        string $taskLogContent,
        array $taskLogMetadata = [],
    ): array {
        $story->setAssignedAgent($agent);
        $story->setStatus(TaskStatus::InProgress);

        if ($storyStatusForExecution !== null) {
            $story->setStoryStatus($storyStatusForExecution);
        }

        $requestRef = $this->requestCorrelation->getCurrentRequestRef();
        $traceRef = Uuid::v7()->toRfc4122();
        $execution = $this->taskExecutionService->createExecution(
            task: $story,
            requestedAgent: $agent,
            skillSlug: $skillSlug,
            triggerType: $triggerType,
            requestRef: $requestRef,
            traceRef: $traceRef,
            workflowStepKey: $workflowStepKey,
        );

        // Stored in DB for the in-app log UI, so the human-facing message stays in French.
        $this->taskExecutionService->logDispatch(
            execution: $execution,
            action: $taskLogAction,
            content: $taskLogContent,
            metadata: [
                'agentId' => (string) $agent->getId(),
                'agentName' => $agent->getName(),
                'skillSlug' => $skillSlug,
                'roleSlug' => $roleSlug,
                ...$taskLogMetadata,
            ],
        );

        $this->bus->dispatch(new AgentTaskMessage(
            taskId: (string) $story->getId(),
            agentId: (string) $agent->getId(),
            skillSlug: $skillSlug,
            taskExecutionId: (string) $execution->getId(),
            requestRef: $requestRef,
            traceRef: $traceRef,
        ));

        $this->logService->record(
            source: 'backend',
            category: 'runtime',
            level: 'info',
            title: $triggerType === TaskExecutionTrigger::Rework ? 'Agent task rework dispatched' : 'Agent task dispatched',
            // Stored in DB for the in-app log UI, so the human-facing message stays in French.
            message: sprintf('Dispatch de %s vers %s avec le skill %s', $story->getTitle(), $agent->getName(), $skillSlug),
            options: [
                'project_id' => (string) $story->getProject()->getId(),
                'task_id' => (string) $story->getId(),
                'agent_id' => (string) $agent->getId(),
                'request_ref' => $requestRef,
                'trace_ref' => $traceRef,
                'context' => [
                    'task_execution_id' => (string) $execution->getId(),
                    'story_status' => $story->getStoryStatus()?->value,
                    'skill_slug' => $skillSlug,
                    'role_slug' => $roleSlug,
                    'trigger_type' => $triggerType->value,
                    ...$taskLogMetadata,
                ],
            ],
        );

        return ['agent' => $agent, 'skill' => $skillSlug, 'executionId' => (string) $execution->getId()];
    }
}
