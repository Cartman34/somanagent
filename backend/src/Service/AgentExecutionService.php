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
use App\Repository\TaskLogRepository;
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
        private readonly TaskLogRepository     $taskLogRepository,
        private readonly TaskService           $taskService,
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
        $prompt  = $this->buildPrompt($task, $agent, $skill->getContent(), $skillSlug);
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

        // Log raw agent output for the execution journal.
        $this->em->persist(
            (new TaskLog($task, 'agent_response', $response->content))
                ->setAuthorType('agent')
                ->setAuthorName($agent->getName())
                ->setMetadata([
                    'skillSlug' => $skillSlug,
                    'agentId'   => (string) $agent->getId(),
                ])
        );

        $this->logger->info('AgentExecution: response received', [
            'tokens_in'  => $response->inputTokens,
            'tokens_out' => $response->outputTokens,
            'duration_ms' => (int) $response->durationMs,
        ]);

        $questions = $this->extractClarificationQuestions($response->content);
        $normalizedContent = mb_strtolower($response->content);
        $isClarificationRequest = count($questions) >= 2
            || str_contains($normalizedContent, 'questions')
            || str_contains($normalizedContent, 'précis')
            || str_contains($normalizedContent, 'clarification');

        if ($questions !== [] && $isClarificationRequest) {
            foreach ($questions as $question) {
                $this->taskService->addComment(
                    task: $task,
                    content: $question,
                    authorType: 'agent',
                    authorName: $agent->getName(),
                    requiresAnswer: true,
                    metadata: [
                        'context'   => 'clarification_request',
                        'skillSlug' => $skillSlug,
                        'agentId'   => (string) $agent->getId(),
                    ],
                    action: 'agent_question',
                );
            }

            $task->setStatus(TaskStatus::InProgress);
            $this->em->flush();
            return;
        }

        if ($skillSlug === 'tech-planning') {
            $this->handlePlanningResponse($task, $agent, $response->content);
        } elseif ($skillSlug === 'product-owner') {
            $this->handleProductOwnerResponse($task, $response->content);
        } else {
            $task->setStatus(TaskStatus::Done)->setProgress(100);
        }

        $this->em->flush();
    }

    /**
     * Construit le Prompt final pour la tâche.
     */
    private function buildPrompt(Task $task, Agent $agent, string $skillContent, string $skillSlug): Prompt
    {
        $instruction = $task->getTitle();
        if ($task->getDescription() !== null) {
            $instruction .= "\n\n" . $task->getDescription();
        }

        $context = [
            'task' => [
                'type'      => $task->getType()->value,
                'priority'  => $task->getPriority()->value,
                'status'    => $task->getStatus()->value,
                'story'     => $task->getStoryStatus()?->value,
                'branch'    => $task->getBranchName(),
                'parent'    => $task->getParent()?->getTitle(),
            ],
            'agent_identity' => [
                'name'        => $agent->getName(),
                'description' => $agent->getDescription(),
                'role'        => $agent->getRole()?->getName(),
                'role_slug'   => $agent->getRole()?->getSlug(),
                'role_mission' => $agent->getRole()?->getDescription(),
            ],
            'execution' => [
                'skill' => $skillSlug,
            ],
        ];

        if ($task->getProject() !== null) {
            $context['project'] = [
                'name'        => $task->getProject()->getName(),
                'description' => $task->getProject()->getDescription(),
                'team'        => $task->getProject()->getTeam()?->getName(),
                'modules'     => array_map(
                    static fn($module) => $module->getName(),
                    $task->getProject()->getModules()->toArray(),
                ),
            ];
        }

        $conversation = $this->buildConversationContext($task);
        if ($conversation !== []) {
            $context['ticket_conversation'] = $conversation;
        }

        return Prompt::create($skillContent, $instruction, $context);
    }

    private function buildConversationContext(Task $task): array
    {
        $logs = $this->taskLogRepository->findByTask($task);
        $comments = array_values(array_filter($logs, static fn(TaskLog $log) => $log->getKind() === 'comment'));
        if ($comments === []) {
            return [];
        }

        $slice = array_slice($comments, -12);

        return array_map(static fn(TaskLog $log) => [
            'author'          => $log->getAuthorName() ?? $log->getAuthorType() ?? 'system',
            'author_type'     => $log->getAuthorType(),
            'action'          => $log->getAction(),
            'requires_answer' => $log->requiresAnswer(),
            'reply_to'        => $log->getReplyToLogId()?->toRfc4122(),
            'context'         => $log->getMetadata()['context'] ?? null,
            'content'         => $log->getContent(),
            'created_at'      => $log->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], $slice);
    }

    /**
     * Detects agent clarification questions from the raw response so they can
     * become actionable ticket comments instead of opaque free text.
     *
     * @return string[]
     */
    private function extractClarificationQuestions(string $content): array
    {
        $questions = [];
        foreach (preg_split('/\R+/', $content) ?: [] as $line) {
            $candidate = trim(preg_replace('/^[-*0-9.)\s]+/', '', trim($line)) ?? '');
            if ($candidate === '' || !str_ends_with($candidate, '?')) {
                continue;
            }

            if (mb_strlen($candidate) < 8) {
                continue;
            }

            $questions[] = $candidate;
        }

        return array_values(array_unique(array_slice($questions, 0, 5)));
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
        $story->setStatus(TaskStatus::Done)->setProgress(100);
        $this->em->persist(new TaskLog($story, 'planning_completed', $summary));

        $this->logger->info('AgentExecution: planning completed', [
            'tasks_created' => count($createdTasks),
            'branch'        => $plan->branch,
            'needs_design'  => $plan->needsDesign,
        ]);
    }

    /**
     * Stores the Product Owner output back into the story and moves it to "ready".
     */
    private function handleProductOwnerResponse(Task $story, string $rawContent): void
    {
        $content = trim($rawContent);
        if ($content !== '') {
            $story->setDescription($content);
        }

        if (preg_match('/^##\s+(.+)$/m', $content, $matches) === 1) {
            $story->setTitle(trim($matches[1]));
        }

        if ($story->getStoryStatus() === StoryStatus::New) {
            try {
                $story->transitionStoryTo(StoryStatus::Ready);
            } catch (\LogicException $e) {
                $this->logger->warning('AgentExecution: could not transition PO story status', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $story->setStatus(TaskStatus::Done)->setProgress(100);

        $this->em->persist(new TaskLog(
            $story,
            'product_owner_completed',
            'La demande a été reformulée en user story prête pour validation.',
        ));
    }
}
