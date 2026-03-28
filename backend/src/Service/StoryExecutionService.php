<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Agent;
use App\Entity\Task;
use App\Entity\TaskLog;
use App\Enum\StoryStatus;
use App\Enum\TaskStatus;
use App\Message\AgentTaskMessage;
use App\Repository\AgentRepository;
use App\Repository\WorkflowStepRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

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
        $team = $story->getProject()->getTeam();

        if ($team !== null) {
            return $this->agentRepository->findActiveByRoleSlugAndTeam($roleSlug, $team);
        }

        return $this->agentRepository->findActiveByRoleSlug($roleSlug);
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
     * @return array{agent: Agent, skill: string}
     */
    public function execute(Task $story, ?Agent $agent = null): array
    {
        if (!$this->canExecute($story)) {
            throw new \RuntimeException(
                sprintf('No execution config for storyStatus "%s".', $story->getStoryStatus()?->value ?? 'null')
            );
        }

        ['role' => $roleSlug, 'skill' => $skillSlug, 'transition' => $transition] = $this->resolveExecutionConfig($story);
        $team = $story->getProject()->getTeam();

        if ($agent === null) {
            $agents = $team !== null
                ? $this->agentRepository->findActiveByRoleSlugAndTeam($roleSlug, $team)
                : $this->agentRepository->findActiveByRoleSlug($roleSlug);

            if (empty($agents)) {
                throw new \RuntimeException(
                    sprintf(
                        'No active agent found with role "%s"%s.',
                        $roleSlug,
                        $team !== null ? ' in team "' . $team->getName() . '"' : ''
                    )
                );
            }
            $agent = $agents[0];
        }

        $story->setAssignedAgent($agent);
        $story->setStatus(TaskStatus::InProgress);

        // Transition story status if needed (e.g. approved → planning)
        if ($transition !== null) {
            $story->transitionStoryTo($transition);
        }

        $this->em->persist(new TaskLog(
            $story,
            'execution_dispatched',
            sprintf('Agent %s dispatché avec le skill %s', $agent->getName(), $skillSlug),
        ));
        $this->em->flush();

        $this->bus->dispatch(new AgentTaskMessage(
            taskId:    (string) $story->getId(),
            agentId:   (string) $agent->getId(),
            skillSlug: $skillSlug,
        ));

        return ['agent' => $agent, 'skill' => $skillSlug];
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
        $team        = $story->getProject()->getTeam();

        if ($team !== null) {
            $step = $this->workflowStepRepository->findByTeamAndStoryStatus($team, $storyStatus);
            if ($step !== null && $step->getRoleSlug() !== null && $step->getSkillSlug() !== null) {
                return [
                    'role'       => $step->getRoleSlug(),
                    'skill'      => $step->getSkillSlug(),
                    'transition' => null, // workflow steps do not define auto-transitions yet
                ];
            }
        }

        if (!isset(self::EXECUTION_MAP[$storyStatus->value])) {
            throw new \RuntimeException(
                sprintf('No execution config for storyStatus "%s".', $storyStatus->value)
            );
        }

        return self::EXECUTION_MAP[$storyStatus->value];
    }
}
