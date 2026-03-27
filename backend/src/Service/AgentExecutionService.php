<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Agent;
use App\Entity\Role;
use App\Entity\Task;
use App\Entity\TaskDependency;
use App\Entity\TaskLog;
use App\Entity\TokenUsage;
use App\Enum\StoryStatus;
use App\Enum\TaskStatus;
use App\Enum\TaskType;
use App\Repository\RoleRepository;
use App\Repository\SkillRepository;
use App\ValueObject\Prompt;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Orchestre l'exécution d'une tâche par un agent IA.
 *
 * Flow :
 *  1. Charger le skill depuis la BDD
 *  2. Construire le prompt (skill + contexte de la tâche)
 *  3. Récupérer l'adapter adapté au ConnectorType de l'agent
 *  4. Appeler l'agent, persister TokenUsage + TaskLog
 *  5. Si skill = tech-planning → parser le JSON, créer les sous-tâches + dépendances, faire avancer la story
 *  6. Sinon → passer la tâche à Done
 */
final class AgentExecutionService
{
    public function __construct(
        private readonly AgentPortRegistry     $portRegistry,
        private readonly SkillRepository       $skillRepository,
        private readonly RoleRepository        $roleRepository,
        private readonly PlanningOutputParser  $planningParser,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface       $logger,
    ) {}

    /**
     * Exécute une tâche par l'agent donné avec le skill indiqué.
     *
     * @throws \RuntimeException si le skill est introuvable ou si l'appel agent échoue
     */
    public function execute(Task $task, Agent $agent, string $skillSlug): void
    {
        $skill = $this->skillRepository->findOneBy(['slug' => $skillSlug]);
        if ($skill === null) {
            throw new \RuntimeException("Skill '{$skillSlug}' not found in database.");
        }

        $config  = $agent->getAgentConfig();
        $prompt  = $this->buildPrompt($task, $skill->getContent());
        $adapter = $this->portRegistry->getFor($agent->getConnector());

        $this->logger->info('AgentExecution: calling agent', [
            'task'  => $task->getTitle(),
            'agent' => $agent->getName(),
            'skill' => $skillSlug,
        ]);

        $response = $adapter->sendPrompt($prompt, $config);

        // Persist token usage
        $usage = new TokenUsage(
            agent:        $agent,
            model:        $config->model,
            inputTokens:  $response->inputTokens,
            outputTokens: $response->outputTokens,
            durationMs:   (int) $response->durationMs,
            task:         $task,
        );
        $this->em->persist($usage);

        // Log raw agent output
        $this->em->persist(new TaskLog($task, 'agent_response', $response->content));

        $this->logger->info('AgentExecution: response received', [
            'tokens_in'  => $response->inputTokens,
            'tokens_out' => $response->outputTokens,
            'duration_ms' => (int) $response->durationMs,
        ]);

        if ($skillSlug === 'tech-planning') {
            $this->handlePlanningResponse($task, $agent, $response->content);
        } else {
            $task->setStatus(TaskStatus::Done);
        }

        $this->em->flush();
    }

    /**
     * Construit le Prompt final pour la tâche.
     */
    private function buildPrompt(Task $task, string $skillContent): Prompt
    {
        $instruction = $task->getTitle();
        if ($task->getDescription() !== null) {
            $instruction .= "\n\n" . $task->getDescription();
        }

        $context = [
            'task_type' => $task->getType()->value,
            'priority'  => $task->getPriority()->value,
        ];

        if ($task->isStory() && $task->getStoryStatus() !== null) {
            $context['story_status'] = $task->getStoryStatus()->value;
        }

        if ($task->getProject() !== null) {
            $context['project'] = $task->getProject()->getName();
        }

        return Prompt::create($skillContent, $instruction, $context);
    }

    /**
     * Traite la sortie JSON du skill tech-planning :
     *  - Crée les sous-tâches (type=Task) avec leurs dépendances
     *  - Met à jour la branche Git de la story
     *  - Fait avancer le storyStatus (planning → graphic_design | development)
     */
    private function handlePlanningResponse(Task $story, Agent $leadTech, string $rawContent): void
    {
        try {
            $plan = $this->planningParser->parse($rawContent);
        } catch (\InvalidArgumentException $e) {
            $this->logger->error('AgentExecution: failed to parse planning output', [
                'error' => $e->getMessage(),
                'task'  => $story->getTitle(),
            ]);
            $this->em->persist(new TaskLog($story, 'planning_parse_error', $e->getMessage()));
            return;
        }

        // Set branch name on the story
        $story->setBranchName($plan->branch);

        // Create subtasks
        /** @var Task[] $createdTasks */
        $createdTasks = [];
        foreach ($plan->tasks as $planTask) {
            $role = $this->roleRepository->findOneBy(['slug' => $planTask->role]);

            $subtask = new Task(
                project:     $story->getProject(),
                type:        TaskType::Task,
                title:       $planTask->title,
                description: $planTask->description,
                priority:    $planTask->priority,
            );
            $subtask->setParent($story);
            $subtask->setAddedBy($leadTech);
            $subtask->setBranchName($plan->branch);
            $subtask->setStatus(TaskStatus::Backlog);

            if ($role !== null) {
                $subtask->setAssignedRole($role);
            }

            $this->em->persist($subtask);
            $createdTasks[] = $subtask;
        }

        // Create dependencies (flush first so subtask IDs exist)
        $this->em->flush();

        foreach ($plan->tasks as $i => $planTask) {
            foreach ($planTask->dependsOn as $depIndex) {
                $dependency = new TaskDependency($createdTasks[$i], $createdTasks[$depIndex]);
                $this->em->persist($dependency);
            }
        }

        // Unlock tasks that have no dependencies (set them to Todo)
        foreach ($plan->tasks as $i => $planTask) {
            if (empty($planTask->dependsOn)) {
                $createdTasks[$i]->setStatus(TaskStatus::Todo);
            }
        }

        // Advance story status
        if ($story->getStoryStatus() === StoryStatus::Planning) {
            $nextStatus = $plan->needsDesign
                ? StoryStatus::GraphicDesign
                : StoryStatus::Development;

            try {
                $story->transitionStoryTo($nextStatus);
            } catch (\LogicException $e) {
                $this->logger->warning('AgentExecution: could not transition story status', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $summary = sprintf(
            'Plan created: %d tasks, branch=%s, needsDesign=%s',
            count($createdTasks),
            $plan->branch,
            $plan->needsDesign ? 'yes' : 'no',
        );
        $this->em->persist(new TaskLog($story, 'planning_completed', $summary));

        $this->logger->info('AgentExecution: planning completed', [
            'tasks_created' => count($createdTasks),
            'branch'        => $plan->branch,
            'needs_design'  => $plan->needsDesign,
        ]);
    }
}
