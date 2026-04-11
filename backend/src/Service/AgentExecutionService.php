<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Service;

use App\Entity\Agent;
use App\Entity\AgentAction;
use App\Entity\AgentTaskExecutionAttempt;
use App\Entity\Skill;
use App\Entity\Ticket;
use App\Entity\TicketLog;
use App\Entity\TicketTask;
use App\Entity\TicketTaskDependency;
use App\Entity\TokenUsage;
use App\Enum\ClarificationQuestionNecessity;
use App\Enum\TaskStatus;
use App\Adapter\VCS\MockVcsAdapter;
use App\Repository\AgentActionRepository;
use App\Repository\SkillRepository;
use App\Repository\TicketLogRepository;
use App\ValueObject\ConnectorRequest;
use App\ValueObject\Prompt;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates ticket-task execution through the current agent stack.
 *
 * Flow:
 *  1. Load the skill from the database
 *  2. Build the prompt from skill content plus ticket/task context
 *  3. Resolve the connector adapter for the target agent
 *  4. Call the agent and persist TokenUsage plus TicketLog entries
 *  5. When the skill is `tech-planning`, parse the JSON plan, create subtasks, and move the ticket forward
 *  6. Otherwise, mark the task as done
 */
final class AgentExecutionService
{
    /**
     * Wires every dependency required to execute ticket tasks and process agent output.
     */
    public function __construct(
        private readonly ConnectorRegistry     $connectorRegistry,
        private readonly SkillRepository       $skillRepository,
        private readonly AgentActionRepository $agentActionRepository,
        private readonly TicketLogRepository   $ticketLogRepository,
        private readonly TicketTaskService     $ticketTaskService,
        private readonly TicketLogService      $ticketLogService,
        private readonly AgentContextBuilder   $contextBuilder,
        private readonly PlanningOutputParser  $planningParser,
        private readonly VcsRepositoryUrlService $vcsRepositoryUrl,
        private readonly MockVcsAdapter        $mockVcsAdapter,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface       $logger,
    ) {}

    /**
     * Executes an operational ticket task through the current ticket-centric agent stack.
     *
     * The target model keeps ticket history in TicketLog, so this path avoids TaskLog entirely.
     *
     * @throws \RuntimeException if the skill is not found or if the agent call fails
     */
    public function executeTicketTask(TicketTask $task, Agent $agent, string $skillSlug, AgentTaskExecutionAttempt $attempt): void
    {
        $skill = $this->skillRepository->findOneBy(['slug' => $skillSlug]);
        if ($skill === null) {
            throw new \RuntimeException("Skill '{$skillSlug}' not found in database.");
        }

        $config = $agent->getConnectorConfig();
        $prompt = $this->buildPromptForTicketTask($task, $agent, $skill->getContent(), $skillSlug);
        $this->ticketTaskService->captureExecutionResourceSnapshot(
            $attempt,
            $this->buildExecutionResourceSnapshot($task, $agent, $skill, $prompt),
        );
        $adapter = $this->connectorRegistry->getFor($agent->getConnector());

        $this->logger->info('AgentExecution: calling agent for ticket task', [
            'ticket_task' => $task->getTitle(),
            'agent' => $agent->getName(),
            'skill' => $skillSlug,
            'action' => $task->getAgentAction()->getKey(),
        ]);

        $response = $adapter->sendRequest(ConnectorRequest::fromPrompt($prompt, ConnectorRequest::DEFAULT_WORKING_DIRECTORY), $config);

        $usage = new TokenUsage(
            agent: $agent,
            model: $config->model,
            inputTokens: $response->inputTokens,
            outputTokens: $response->outputTokens,
            durationMs: (int) $response->durationMs,
            ticket: $task->getTicket(),
            ticketTask: $task,
            workflowStep: $task->getWorkflowStep(),
        );
        $this->em->persist($usage);

        $this->assertAllowedEffects($task, ['log_agent_response']);

        $this->ticketLogService->log(
            ticket: $task->getTicket(),
            action: 'agent_response',
            content: $response->content,
            ticketTask: $task,
            authorType: 'agent',
            authorName: $agent->getName(),
            metadata: [
                'skillSlug' => $skillSlug,
                'agentId' => (string) $agent->getId(),
                'actionKey' => $task->getAgentAction()->getKey(),
            ],
        );

        $allowedEffects = $this->ticketTaskService->describeExecutionScope($task)['allowed_effects'];
        $canAskClarification = in_array('ask_clarification', $allowedEffects, true);

        if ($canAskClarification) {
            $questions = $this->filterDuplicateQuestions(
                $this->extractClarificationQuestions($response->content),
                $task->getTicket(),
            );

            if ($questions !== []) {
                $this->assertAllowedEffects($task, ['ask_clarification']);
                $hasBlockingQuestions = false;

                foreach ($questions as $question) {
                    $this->ticketLogService->log(
                        ticket: $task->getTicket(),
                        action: 'agent_question',
                        content: $question['content'],
                        ticketTask: $task,
                        kind: 'comment',
                        authorType: 'agent',
                        authorName: $agent->getName(),
                        requiresAnswer: true,
                        metadata: [
                            'context' => 'clarification_request',
                            'necessityLevel' => $question['necessityLevel']->value,
                            'necessityReason' => $question['necessityReason'],
                            'skillSlug' => $skillSlug,
                            'agentId' => (string) $agent->getId(),
                            'actionKey' => $task->getAgentAction()->getKey(),
                        ],
                    );
                    if ($question['necessityLevel']->isBlocking()) {
                        $hasBlockingQuestions = true;
                    }
                }

                if ($hasBlockingQuestions) {
                    $task->setStatus(TaskStatus::InProgress);
                    $this->em->flush();
                    return;
                }
            }

            $pendingBlockingAnswersCount = $this->ticketLogService->countPendingBlockingAnswersForTask($task);
            if ($pendingBlockingAnswersCount > 0) {
                $this->logger->info('AgentExecution: kept task open because blocking clarification questions are still pending', [
                    'ticket' => (string) $task->getTicket()->getId(),
                    'pending_answers' => $pendingBlockingAnswersCount,
                    'action' => $task->getAgentAction()->getKey(),
                ]);
                $task->setStatus(TaskStatus::InProgress);
                $this->em->flush();
                return;
            }
        }

        // TODO: replace skill-slug-based dispatch with a response-handler strategy stored on AgentAction
        //       in the database, so that adding a new action type requires no code change here.
        if ($skillSlug === 'tech-planning') {
            $this->assertAllowedEffects($task, [
                'complete_current_task',
                'replace_planning_tasks',
                'create_subtasks',
                'prepare_branch',
                'update_ticket_progress',
            ]);
            $this->handlePlanningTicketTaskResponse($task, $agent, $response->content);
        } elseif ($skillSlug === 'product-owner') {
            $this->assertAllowedEffects($task, [
                'rewrite_ticket',
                'complete_current_task',
                'complete_ticket',
            ]);
            $this->handleProductOwnerTicketTaskResponse($task, $response->content);
        } else {
            $this->assertAllowedEffects($task, ['complete_current_task']);
            $task->setStatus(TaskStatus::Done)->setProgress(100);
        }
        $this->em->flush();
    }

    /**
     * @return TicketLog[]
     */
    private function buildTicketConversationContext(TicketTask $task): array
    {
        $logs = $this->ticketLogRepository->findByTicket($task->getTicket());
        $comments = array_values(array_filter($logs, static fn(TicketLog $log) => $log->getKind() === 'comment'));
        if ($comments === []) {
            return [];
        }

        return array_slice($comments, -12);
    }

    private function buildPromptForTicketTask(TicketTask $task, Agent $agent, string $skillContent, string $skillSlug): Prompt
    {
        $instruction = $task->getTitle();
        if ($task->getDescription() !== null) {
            $instruction .= "\n\n" . $task->getDescription();
        }

        $context = $this->contextBuilder->buildForTicketTask(
            task: $task,
            agent: $agent,
            skillSlug: $skillSlug,
            ticketComments: $this->buildTicketConversationContext($task),
        );

        return Prompt::create($skillContent, $instruction, $context);
    }

    /**
     * Detects agent clarification questions from the raw response so they can
     * become actionable ticket comments instead of opaque free text.
     *
     * @return array<int, array{content: string, necessityLevel: ClarificationQuestionNecessity, necessityReason: string}>
     */
    private function extractClarificationQuestions(string $content): array
    {
        $questions = [];
        foreach (preg_split('/\R+/', $content) ?: [] as $line) {
            $candidate = trim(preg_replace('/^[-*0-9.)\s]+/', '', trim($line)) ?? '');
            if ($candidate === '') {
                continue;
            }

            $question = $this->parseClarificationQuestion($candidate);
            if ($question === null) {
                continue;
            }

            $questions[] = $question;
        }

        $uniqueQuestions = [];
        foreach ($questions as $question) {
            $normalized = self::normalizeQuestion($question['content']);
            if (isset($uniqueQuestions[$normalized])) {
                continue;
            }

            $uniqueQuestions[$normalized] = $question;
        }

        return array_values(array_slice($uniqueQuestions, 0, 2));
    }

    private static function normalizeQuestion(string $question): string
    {
        $normalized = mb_strtolower(trim($question));
        $normalized = rtrim($normalized, '?!.');
        $normalized = (string) preg_replace('/\s+/', ' ', $normalized);

        return trim($normalized);
    }

    /**
     * @return array{content: string, necessityLevel: ClarificationQuestionNecessity, necessityReason: string}|null
     */
    private function parseClarificationQuestion(string $candidate): ?array
    {
        if (preg_match('/^\[(blocking|important|useful)\]\s+(.+?)\s+::\s+(.+\?)$/iu', $candidate, $matches) === 1) {
            $question = trim($matches[3]);
            $reason = trim($matches[2]);
            if (mb_strlen($question) < 8 || $reason === '') {
                return null;
            }

            return [
                'content' => $question,
                'necessityLevel' => ClarificationQuestionNecessity::from(mb_strtolower($matches[1])),
                'necessityReason' => $reason,
            ];
        }

        return null;
    }

    /**
     * @param array<int, array{content: string, necessityLevel: ClarificationQuestionNecessity, necessityReason: string}> $candidates
     * @return array<int, array{content: string, necessityLevel: ClarificationQuestionNecessity, necessityReason: string}>
     */
    private function filterDuplicateQuestions(array $candidates, Ticket $ticket): array
    {
        if ($candidates === []) {
            return [];
        }

        $existingLogs = $this->ticketLogRepository->findAgentQuestionsByTicket($ticket);
        $existingNormalized = [];
        foreach ($existingLogs as $log) {
            $existingNormalized[self::normalizeQuestion($log->getContent())] = true;
        }

        $filtered = array_values(array_filter(
            $candidates,
            static fn(array $question) => !isset($existingNormalized[self::normalizeQuestion($question['content'])]),
        ));

        $skipped = count($candidates) - count($filtered);
        if ($skipped > 0) {
            $this->logger->info('AgentExecution: filtered duplicate questions', [
                'ticket' => (string) $ticket->getId(),
                'skipped' => $skipped,
                'kept' => count($filtered),
            ]);
        }

        return $filtered;
    }

    /**
     * Ensures the backend effects attempted by the current handler stay within the declared execution scope.
     *
     * @param string[] $effects
     */
    private function assertAllowedEffects(TicketTask $task, array $effects): void
    {
        $allowedEffects = $this->ticketTaskService->describeExecutionScope($task)['allowed_effects'];

        foreach ($effects as $effect) {
            if (in_array($effect, $allowedEffects, true)) {
                continue;
            }

            throw new \LogicException(sprintf(
                'Execution effect "%s" is outside the declared scope for action "%s".',
                $effect,
                $task->getAgentAction()->getKey(),
            ));
        }
    }

    /**
     * Builds the immutable execution resource snapshot persisted on the execution record.
     *
     * @return array<string, mixed>
     */
    private function buildExecutionResourceSnapshot(TicketTask $task, Agent $agent, Skill $skill, Prompt $prompt): array
    {
        $scope = $this->ticketTaskService->describeExecutionScope($task);
        $role = $agent->getRole();

        return [
            'capturedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'agent' => [
                'resourceKind' => 'database_agent',
                'filePath' => null,
                'id' => $agent->getId()->toRfc4122(),
                'name' => $agent->getName(),
                'description' => $agent->getDescription(),
                'connector' => $agent->getConnector()->value,
                'role' => $role ? [
                    'id' => $role->getId()->toRfc4122(),
                    'slug' => $role->getSlug(),
                    'name' => $role->getName(),
                ] : null,
                'config' => $agent->getConnectorConfig()->toArray(),
            ],
            'skill' => [
                'resourceKind' => 'skill_file',
                'id' => $skill->getId()->toRfc4122(),
                'slug' => $skill->getSlug(),
                'name' => $skill->getName(),
                'description' => $skill->getDescription(),
                'source' => $skill->getSource()->value,
                'originalSource' => $skill->getOriginalSource(),
                'filePath' => $skill->getFilePath(),
                'content' => $skill->getContent(),
            ],
            'prompt' => [
                'instruction' => $prompt->getTaskInstruction(),
                'context' => $prompt->getContext(),
                'rendered' => $prompt->build(),
            ],
            'scope' => [
                'taskActions' => $scope['task_actions'],
                'ticketTransitions' => $scope['ticket_transitions'],
                'allowedEffects' => $scope['allowed_effects'],
            ],
            'limits' => [
                'agentFilePathAvailable' => false,
                'agentFilePathReason' => 'Agents are database-backed records and currently have no dedicated file path.',
            ],
        ];
    }

    private function handlePlanningTicketTaskResponse(TicketTask $planningTask, Agent $leadTech, string $rawContent): void
    {
        $ticket = $planningTask->getTicket();

        try {
            $plan = $this->planningParser->parse($rawContent);
        } catch (\InvalidArgumentException $e) {
            $message = sprintf(
                'Invalid lead-tech plan: %s Replay the planning step with strict JSON and dependsOn limited to previous indexes.',
                $e->getMessage(),
            );

            $this->logger->error('AgentExecution: failed to parse ticket planning output', [
                'error' => $message,
                'ticket' => $ticket->getTitle(),
            ]);
            $this->ticketLogService->log($ticket, 'planning_parse_error', $message, $planningTask);
            $this->em->flush();
            throw new \RuntimeException($message, previous: $e);
        }

        $ticket->setBranchName($plan->branch);
        $this->simulatePlanningBranchCreationForTicket($ticket, $planningTask, $plan->branch);

        $removedSubtasks = $this->removeExistingPlanningTicketTasks($ticket, $planningTask);
        if ($removedSubtasks > 0) {
            $this->ticketLogService->log(
                $ticket,
                'planning_replaced',
                sprintf('Previous plan removed before regeneration (%d subtask%s).', $removedSubtasks, $removedSubtasks > 1 ? 's' : ''),
                $planningTask,
            );
        }

        /** @var TicketTask[] $createdTasks */
        $createdTasks = [];
        foreach ($plan->tasks as $planTask) {
            $action = $this->agentActionRepository->findOneByKey($planTask->actionKey);
            if ($action === null) {
                throw new \RuntimeException(sprintf('Unknown planned agent action "%s".', $planTask->actionKey));
            }

            $subtask = new TicketTask(
                ticket: $ticket,
                agentAction: $action,
                title: $planTask->title,
                description: $planTask->description,
                priority: $planTask->priority,
            );
            $subtask->setAddedBy($leadTech);
            $subtask->setBranchName($plan->branch);
            $subtask
                ->setStatus(TaskStatus::Backlog)
                ->setAssignedRole($action->getRole())
                ->setWorkflowStep($this->ticketTaskService->resolveWorkflowStepForAction($ticket, $action));
            $this->em->persist($subtask);
            $createdTasks[] = $subtask;
        }

        $this->em->flush();

        foreach ($plan->tasks as $i => $planTask) {
            foreach ($planTask->dependsOn as $depIndex) {
                $this->em->persist(new TicketTaskDependency($createdTasks[$i], $createdTasks[$depIndex]));
            }
        }

        foreach ($plan->tasks as $i => $planTask) {
            if ($planTask->dependsOn === []) {
                $createdTasks[$i]->setStatus(TaskStatus::Todo);
            }
        }

        $summary = sprintf(
            'Plan created: %d tasks, branch=%s, needsDesign=%s',
            count($createdTasks),
            $plan->branch,
            $plan->needsDesign ? 'yes' : 'no',
        );

        $planningTask->setStatus(TaskStatus::Done)->setProgress(100);
        $ticket->setProgress(100);
        $this->ticketLogService->log($ticket, 'planning_completed', $summary, $planningTask);
    }

    private function simulatePlanningBranchCreationForTicket(Ticket $ticket, TicketTask $planningTask, string $branchName): void
    {
        $repository = $this->vcsRepositoryUrl->resolve($ticket->getProject()->getRepositoryUrl());
        if ($repository === null) {
            return;
        }

        $this->mockVcsAdapter->createBranch(
            owner: $repository['owner'],
            repo: $repository['repo'],
            branch: $branchName,
        );

        $branchUrl = $this->vcsRepositoryUrl->buildBranchUrl($ticket->getProject()->getRepositoryUrl(), $branchName);

        $this->ticketLogService->log(
            $ticket,
            'branch_prepared',
            sprintf('Simulated planning branch created: %s', $branchName),
            $planningTask,
            metadata: [
                'provider' => $repository['provider'],
                'repository' => $repository['owner'] . '/' . $repository['repo'],
                'branch' => $branchName,
                'branchUrl' => $branchUrl,
                'mode' => 'mock',
            ],
        );
    }

    private function removeExistingPlanningTicketTasks(Ticket $ticket, TicketTask $planningTask): int
    {
        $tasks = $ticket->getTasks()->toArray();
        $removed = 0;

        foreach ($tasks as $task) {
            if (!$task instanceof TicketTask) {
                continue;
            }

            if ($task === $planningTask) {
                continue;
            }

            if ($task->getAddedBy() === null) {
                continue;
            }

            $this->em->remove($task);
            ++$removed;
        }

        $this->em->flush();

        return $removed;
    }
    private function handleProductOwnerTicketTaskResponse(TicketTask $task, string $rawContent): void
    {
        $ticket = $task->getTicket();
        $content = trim($rawContent);

        if (preg_match('/^##\s+(.+)$/m', $content, $matches) === 1) {
            $ticket->setTitle(trim($matches[1]));
            $ticket->setDescription($this->buildPoDescription($content));
        }

        $ticket->setStatus(TaskStatus::Done)->setProgress(100);
        $task->setStatus(TaskStatus::Done)->setProgress(100);

        $this->ticketLogService->log(
            $ticket,
            'product_owner_completed',
            'The request was reframed into a user story ready for validation.',
            $task,
        );
    }

    /**
     * Builds the ticket description from a PO response by stripping the title heading
     * and the Questions section so only descriptive content is stored.
     */
    private function buildPoDescription(string $content): string
    {
        // Remove the ## Title line
        $result = (string) preg_replace('/^##\s+.+\R?/m', '', $content);

        // Remove the ### Questions section (up to the next ### heading or end of string)
        $result = (string) preg_replace('/###\s+Questions\s*\R.*?(?=###|\z)/su', '', $result);

        return trim($result);
    }
}
