<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Service;

use App\Entity\Agent;
use App\Entity\AgentAction;
use App\Entity\Ticket;
use App\Entity\TicketLog;
use App\Entity\TicketTask;
use App\Entity\TicketTaskDependency;
use App\Entity\TokenUsage;
use App\Enum\TaskStatus;
use App\Adapter\VCS\MockVcsAdapter;
use App\Repository\AgentActionRepository;
use App\Repository\SkillRepository;
use App\Repository\TicketLogRepository;
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
 *  4. Appeler l'agent, persister TokenUsage + TicketLog
 *  5. Si skill = tech-planning → parser le JSON, créer les sous-tâches + dépendances, faire avancer le ticket
 *  6. Sinon → passer la tâche à Done
 */
final class AgentExecutionService
{
    public function __construct(
        private readonly AgentPortRegistry     $portRegistry,
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
    public function executeTicketTask(TicketTask $task, Agent $agent, string $skillSlug): void
    {
        $skill = $this->skillRepository->findOneBy(['slug' => $skillSlug]);
        if ($skill === null) {
            throw new \RuntimeException("Skill '{$skillSlug}' not found in database.");
        }

        $config = $agent->getAgentConfig();
        $prompt = $this->buildPromptForTicketTask($task, $agent, $skill->getContent(), $skillSlug);
        $adapter = $this->portRegistry->getFor($agent->getConnector());

        $this->logger->info('AgentExecution: calling agent for ticket task', [
            'ticket_task' => $task->getTitle(),
            'agent' => $agent->getName(),
            'skill' => $skillSlug,
            'action' => $task->getAgentAction()->getKey(),
        ]);

        $response = $adapter->sendPrompt($prompt, $config);

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

        $questions = $this->extractClarificationQuestions($response->content);
        $questions = $this->filterDuplicateQuestions($questions, $task->getTicket());
        $normalizedContent = mb_strtolower($response->content);
        $isClarificationRequest = count($questions) >= 2
            || str_contains($normalizedContent, 'questions')
            || str_contains($normalizedContent, 'précis')
            || str_contains($normalizedContent, 'clarification');

        if ($questions !== [] && $isClarificationRequest) {
            foreach ($questions as $question) {
                $this->ticketLogService->log(
                    ticket: $task->getTicket(),
                    action: 'agent_question',
                    content: $question,
                    ticketTask: $task,
                    kind: 'comment',
                    authorType: 'agent',
                    authorName: $agent->getName(),
                    requiresAnswer: true,
                    metadata: [
                        'context' => 'clarification_request',
                        'skillSlug' => $skillSlug,
                        'agentId' => (string) $agent->getId(),
                        'actionKey' => $task->getAgentAction()->getKey(),
                    ],
                );
            }

            $task->setStatus(TaskStatus::InProgress);
            $this->em->flush();
            return;
        }

        if ($skillSlug === 'tech-planning') {
            $this->handlePlanningTicketTaskResponse($task, $agent, $response->content);
        } elseif ($skillSlug === 'product-owner') {
            $this->handleProductOwnerTicketTaskResponse($task, $response->content);
        } else {
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

    private static function normalizeQuestion(string $question): string
    {
        $normalized = mb_strtolower(trim($question));
        $normalized = rtrim($normalized, '?!.');
        $normalized = (string) preg_replace('/\s+/', ' ', $normalized);

        return trim($normalized);
    }

    /**
     * @param string[] $candidates
     * @return string[]
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
            static fn(string $q) => !isset($existingNormalized[self::normalizeQuestion($q)]),
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

    private function handlePlanningTicketTaskResponse(TicketTask $planningTask, Agent $leadTech, string $rawContent): void
    {
        $ticket = $planningTask->getTicket();

        try {
            $plan = $this->planningParser->parse($rawContent);
        } catch (\InvalidArgumentException $e) {
            $message = sprintf(
                'Plan lead-tech invalide: %s Rejouer la planification avec un JSON strict et des dependsOn limités à des indices précédents.',
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
                sprintf('Ancien plan supprimé avant régénération (%d sous-tâche%s).', $removedSubtasks, $removedSubtasks > 1 ? 's' : ''),
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
            sprintf('Branche simulée pour le planning: %s', $branchName),
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
        if ($content !== '') {
            $ticket->setDescription($content);
        }

        if (preg_match('/^##\s+(.+)$/m', $content, $matches) === 1) {
            $ticket->setTitle(trim($matches[1]));
        }

        $ticket->setStatus(TaskStatus::Done)->setProgress(100);
        $task->setStatus(TaskStatus::Done)->setProgress(100);

        $this->ticketLogService->log(
            $ticket,
            'product_owner_completed',
            'La demande a été reformulée en user story prête pour validation.',
            $task,
        );
    }
}
