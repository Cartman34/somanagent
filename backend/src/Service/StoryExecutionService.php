<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Agent;
use App\Entity\Task;
use App\Entity\TaskLog;
use App\Enum\StoryStatus;
use App\Message\AgentTaskMessage;
use App\Repository\AgentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Orchestrates the agent execution for a user story or bug.
 *
 * Maps the current storyStatus to the appropriate agent role and skill,
 * optionally advances the story to its next status, then dispatches an
 * AgentTaskMessage to the async Messenger transport (Redis).
 *
 * Supported states:
 *  - approved      → lead-tech  / tech-planning   → transitions to planning
 *  - graphic_design → ui-ux-designer / ui-design  → stays (agent completes after)
 *  - development   → php-dev   / php-backend-dev  → stays (agent completes after)
 *  - code_review   → lead-tech  / code-reviewer   → stays (agent completes after)
 */
final class StoryExecutionService
{
    /**
     * Maps storyStatus.value → [roleSlug, skillSlug, ?StoryStatus to transition to before dispatch].
     *
     * @var array<string, array{role: string, skill: string, transition: StoryStatus|null}>
     */
    private const EXECUTION_MAP = [
        'approved'       => ['role' => 'lead-tech',      'skill' => 'tech-planning',   'transition' => StoryStatus::Planning],
        'graphic_design' => ['role' => 'ui-ux-designer', 'skill' => 'ui-design',       'transition' => null],
        'development'    => ['role' => 'php-dev',         'skill' => 'php-backend-dev', 'transition' => null],
        'code_review'    => ['role' => 'lead-tech',       'skill' => 'code-reviewer',   'transition' => null],
    ];

    public function __construct(
        private readonly AgentRepository        $agentRepository,
        private readonly MessageBusInterface    $bus,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Checks whether this story's current status has an automated execution defined.
     */
    public function canExecute(Task $story): bool
    {
        return $story->isStory()
            && $story->getStoryStatus() !== null
            && isset(self::EXECUTION_MAP[$story->getStoryStatus()->value]);
    }

    /**
     * Returns the available agents for this story's current status, or an empty array
     * if no execution config exists for the current status.
     *
     * @return Agent[]
     */
    public function availableAgents(Task $story): array
    {
        if (!$this->canExecute($story)) {
            return [];
        }

        $config = self::EXECUTION_MAP[$story->getStoryStatus()->value];
        return $this->agentRepository->findActiveByRoleSlug($config['role']);
    }

    /**
     * Executes the story using the given agent (or the first available agent if null).
     *
     * - Validates execution is possible for the current storyStatus
     * - Optionally transitions the story status before dispatching
     * - Dispatches an AgentTaskMessage to the async transport
     *
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

        $config = self::EXECUTION_MAP[$story->getStoryStatus()->value];

        if ($agent === null) {
            $agents = $this->agentRepository->findActiveByRoleSlug($config['role']);
            if (empty($agents)) {
                throw new \RuntimeException(
                    sprintf('No active agent found with role "%s".', $config['role'])
                );
            }
            $agent = $agents[0];
        }

        // Transition story status if needed (e.g. approved → planning)
        if ($config['transition'] !== null) {
            $story->transitionStoryTo($config['transition']);
        }

        $this->em->persist(new TaskLog(
            $story,
            'execution_dispatched',
            sprintf('Agent %s dispatché avec le skill %s', $agent->getName(), $config['skill']),
        ));
        $this->em->flush();

        $this->bus->dispatch(new AgentTaskMessage(
            taskId:    (string) $story->getId(),
            agentId:   (string) $agent->getId(),
            skillSlug: $config['skill'],
        ));

        return ['agent' => $agent, 'skill' => $config['skill']];
    }
}
